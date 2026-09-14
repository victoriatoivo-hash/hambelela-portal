<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/policy-system.php';
requireLogin();
$db=db(); hrPolicyEnsureSchema($db); $user=currentUser();

function policyBack(string $message, bool $ok=false): void {
    $_SESSION[$ok?'policy_success':'policy_error']=$message;
    header('Location: policies.php'); exit;
}

try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Invalid request method.');
    hrPolicyVerifyCsrf((string)($_POST['csrf']??''));
    $action=(string)($_POST['action']??'');
    if ($action==='save_draft') {
        if ($user['role']==='employee') throw new RuntimeException('Owner or administrator access is required.');
        if (empty($_FILES['policy_file']) || $_FILES['policy_file']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Select the approved DOCX policy file.');
        $f=$_FILES['policy_file'];
        if ((int)$f['size']>20971520) throw new RuntimeException('Policy files may not exceed 20 MB.');
        $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if ($ext!=='docx') throw new RuntimeException('Only DOCX policy files can be converted into the required digital viewer.');
        $allowed=array('application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip','application/octet-stream');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mime,$allowed,true)) throw new RuntimeException('The uploaded file type is not permitted.');
        $title=trim((string)($_POST['title']??'')); $ver=trim((string)($_POST['version_number']??''));
        if ($title===''||$ver==='') throw new RuntimeException('Title and version are required.');
        $created=(string)($_POST['created_date']??''); $effective=(string)($_POST['effective_date']??'');
        if (!$created||!$effective) throw new RuntimeException('Created and effective dates are required.');
        $dir=hrPolicyPrivateDir(); if (!is_dir($dir) && !mkdir($dir,0750,true)) throw new RuntimeException('Private policy storage could not be created.');
        $stored=bin2hex(random_bytes(16)).'.'.$ext; $target=$dir.DIRECTORY_SEPARATOR.$stored;
        if (!move_uploaded_file($f['tmp_name'],$target)) throw new RuntimeException('The policy file could not be stored.');
        $hash=hash_file('sha256',$target);
        try {
            if ($ext!=='docx') throw new RuntimeException('Digital policy viewing currently requires the approved DOCX format.');
            $digital=hrPolicyDocxDigitalHtml($target);
        } catch (Throwable $conversionError) {
            @unlink($target);
            throw $conversionError;
        }
        $db->beginTransaction();
        $policyId=(int)($_POST['policy_id']??0);
        if (!$policyId) { $s=$db->prepare("INSERT INTO hr_policies (title,policy_type,created_by) VALUES (?,?,?)"); $s->execute(array($title,(string)($_POST['policy_type']??'mandatory_policy'),(int)$user['id'])); $policyId=(int)$db->lastInsertId(); }
        $s=$db->prepare("INSERT INTO hr_policy_versions (policy_id,version_number,title,created_date,effective_date,acknowledgement_deadline,next_review,file_path,original_filename,mime_type,file_size,document_hash,digital_html,digital_hash,digital_generated_at,acknowledgement_required,acknowledgement_text_version,changes_summary,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?)");
        $s->execute(array($policyId,$ver,$title,$created,$effective,($_POST['acknowledgement_deadline']??null)?:null,trim((string)($_POST['next_review']??'')),$stored,$f['name'],$mime,(int)$f['size'],$hash,$digital['html'],$digital['hash'],!empty($_POST['acknowledgement_required'])?1:0,HR_POLICY_ACK_TEXT_VERSION,trim((string)($_POST['changes_summary']??'')),(int)$user['id']));
        $versionId=(int)$db->lastInsertId(); hrPolicyAudit($db,'draft_created',$policyId,$versionId,null,array('hash'=>$hash)); $db->commit();
        policyBack('Policy draft saved. Review it before publishing.',true);
    }
    if ($action==='mark_ready' || $action==='return_draft') {
        if ($user['role']==='employee') throw new RuntimeException('Owner or administrator access is required.');
        $id=(int)($_POST['version_id']??0); $v=hrPolicyVersion($db,$id);
        if (!$v) throw new RuntimeException('Policy version not found.');
        $from=$action==='mark_ready'?'draft':'ready_to_publish';
        $to=$action==='mark_ready'?'ready_to_publish':'draft';
        if ($v['status']!==$from) throw new RuntimeException('The policy state changed. Refresh the page and try again.');
        $q=$db->prepare("UPDATE hr_policy_versions SET status=? WHERE id=? AND status=?");
        $q->execute(array($to,$id,$from));
        if ($q->rowCount()!==1) throw new RuntimeException('The policy state changed. Refresh the page and try again.');
        hrPolicyAudit($db,$action==='mark_ready'?'version_marked_ready':'version_returned_to_draft',(int)$v['policy_id'],$id,null);
        policyBack($action==='mark_ready'?'Policy marked Ready to Publish. Employees have not been notified.':'Policy returned to Draft. Employees have not been notified.',true);
    }
    if ($action==='publish') {
        if ($user['role']==='employee') throw new RuntimeException('Owner or administrator access is required.');
        $id=(int)($_POST['version_id']??0);
        $db->beginTransaction();
        $lock=$db->prepare("SELECT v.*,p.policy_type,p.current_version_id FROM hr_policy_versions v JOIN hr_policies p ON p.id=v.policy_id WHERE v.id=? FOR UPDATE");
        $lock->execute(array($id)); $v=$lock->fetch(PDO::FETCH_ASSOC);
        if (!$v) throw new RuntimeException('Policy version not found.');
        if ($v['status']!=='ready_to_publish') throw new RuntimeException('Only a policy marked Ready to Publish can be published.');
        $metadataIssues=hrPolicyMetadataMismatches($v);
        if ($metadataIssues) throw new RuntimeException('Policy metadata requires review before publication. '.implode(' ',$metadataIssues));
        $db->prepare("UPDATE hr_policy_versions SET status='superseded',superseded_at=NOW() WHERE policy_id=? AND status='published'")->execute(array($v['policy_id']));
        $publisher=trim((string)($user['name']??$user['full_name']??$user['username']??$user['email']??'Owner/Admin'));
        $publish=$db->prepare("UPDATE hr_policy_versions SET status='published',published_by=?,published_by_name=?,published_at=NOW() WHERE id=? AND status='ready_to_publish'");
        $publish->execute(array((int)$user['id'],$publisher,$id));
        if ($publish->rowCount()!==1) throw new RuntimeException('This policy has already been published or its state changed.');
        $db->prepare("UPDATE hr_policies SET current_version_id=?,status='published' WHERE id=?")->execute(array($id,$v['policy_id']));
        $assigned=0; $notified=0;
        if ((int)$v['acknowledgement_required']===1) {
            $emps=$db->query("SELECT u.id AS user_id,e.id AS employee_id FROM users u JOIN employees e ON e.id=u.employee_id WHERE e.status='active' AND u.role='employee'")->fetchAll(PDO::FETCH_ASSOC);
            foreach($emps as $emp){
                $a=$db->prepare("INSERT IGNORE INTO hr_policy_assignments (policy_id,version_id,employee_id,user_id) VALUES (?,?,?,?)");
                $a->execute(array($v['policy_id'],$id,$emp['employee_id'],$emp['user_id'])); $assigned+=$a->rowCount();
                $q=$db->prepare("INSERT IGNORE INTO hr_policy_notifications (version_id,user_id) VALUES (?,?)"); $q->execute(array($id,$emp['user_id']));
                if ($q->rowCount()>0 && hrTableExists($db,'notifications')) {
                    $n=$db->prepare("INSERT INTO notifications (user_id,title,message,type,action_url) VALUES (?,?,?,'info',?)");
                    $n->execute(array($emp['user_id'],'HR Policy — Acknowledgement Required',$v['title'].' Version '.$v['version_number'].'. Complete by '.date('j F Y',strtotime($v['acknowledgement_deadline'])).'.','policy-view.php?id='.$id));
                    $db->prepare("UPDATE hr_policy_notifications SET notification_id=? WHERE version_id=? AND user_id=?")->execute(array($db->lastInsertId(),$id,$emp['user_id']));
                    $notified++;
                }
            }
        }
        $db->prepare("UPDATE hr_policy_versions SET employees_assigned=?,notifications_created=? WHERE id=?")->execute(array($assigned,$notified,$id));
        hrPolicyAudit($db,'version_published',(int)$v['policy_id'],$id,null,array('hash'=>$v['document_hash'],'employees_assigned'=>$assigned,'notifications_created'=>$notified)); $db->commit();
        policyBack('Policy published. Required employees have been notified.',true);
    }
    if ($action==='mark_end') {
        if ($user['role']!=='employee') throw new RuntimeException('Employee access is required.');
        $id=(int)($_POST['version_id']??0); $v=hrPolicyVersion($db,$id); $eid=hrPolicyEmployeeId($user);
        if (!$v||$v['status']!=='published'||!$eid) throw new RuntimeException('Policy is not available.');
        $s=$db->prepare("INSERT INTO hr_policy_acknowledgements (policy_id,version_id,employee_id,user_id,opened_at,reached_end_at) VALUES (?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE reached_end_at=COALESCE(reached_end_at,NOW())");
        $s->execute(array($v['policy_id'],$id,$eid,$user['id'])); hrPolicyAudit($db,'acknowledgement_reached',$v['policy_id'],$id,$eid);
        header('Content-Type: application/json'); echo json_encode(array('ok'=>true)); exit;
    }
    if ($action==='save_progress') {
        if ($user['role']!=='employee') throw new RuntimeException('Employee access is required.');
        $id=(int)($_POST['version_id']??0); $v=hrPolicyVersion($db,$id); $eid=hrPolicyEmployeeId($user);
        if (!$v||!in_array($v['status'],array('published','superseded'),true)||!$eid) throw new RuntimeException('Policy is not available.');
        $percent=max(0,min(100,(float)($_POST['reading_percent']??0))); $position=max(0,(int)($_POST['reading_position']??0));
        $s=$db->prepare("INSERT INTO hr_policy_acknowledgements (policy_id,version_id,employee_id,user_id,opened_at,last_opened_at,reading_percent,reading_position) VALUES (?,?,?,?,NOW(),NOW(),?,?) ON DUPLICATE KEY UPDATE last_opened_at=NOW(),reading_percent=GREATEST(reading_percent,VALUES(reading_percent)),reading_position=VALUES(reading_position)");
        $s->execute(array($v['policy_id'],$id,$eid,$user['id'],$percent,$position));
        header('Content-Type: application/json'); echo json_encode(array('ok'=>true,'reading_percent'=>$percent,'reading_position'=>$position)); exit;
    }
    if ($action==='policy_notification') {
        if ($user['role']!=='employee') throw new RuntimeException('Employee access is required.');
        $id=(int)($_POST['notification_requirement_id']??0);$mode=(string)($_POST['notification_action']??'open');
        $s=$db->prepare("SELECT pn.*,v.acknowledgement_deadline FROM hr_policy_notifications pn JOIN hr_policy_versions v ON v.id=pn.version_id WHERE pn.id=? AND pn.user_id=? AND pn.resolved_at IS NULL LIMIT 1");
        $s->execute(array($id,(int)$user['id']));$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Policy notification is no longer pending.');
        if($mode==='remind'){
            if(date('Y-m-d')>$row['acknowledgement_deadline'])throw new RuntimeException('This acknowledgement is overdue and cannot be deferred.');
            $db->prepare("UPDATE hr_policy_notifications SET delivered_at=COALESCE(delivered_at,NOW()),opened_at=COALESCE(opened_at,NOW()),remind_after=DATE_ADD(NOW(),INTERVAL 12 HOUR) WHERE id=?")->execute(array($id));
        }else{
            $db->prepare("UPDATE hr_policy_notifications SET delivered_at=COALESCE(delivered_at,NOW()),opened_at=COALESCE(opened_at,NOW()),remind_after=NULL WHERE id=?")->execute(array($id));
        }
        header('Content-Type: application/json');echo json_encode(array('ok'=>true));exit;
    }
    if ($action==='sign') {
        if ($user['role']!=='employee') throw new RuntimeException('Employees must sign through their own authenticated account.');
        $id=(int)($_POST['version_id']??0); $v=hrPolicyVersion($db,$id); $eid=hrPolicyEmployeeId($user);
        if (!$v||$v['status']!=='published'||!$eid) throw new RuntimeException('Policy is not available for signature.');
        if (empty($_POST['ack_confirm'])) throw new RuntimeException('Confirm that you have read and understood this policy.');
        $legal=hrPolicyLegalName($db,$eid); $given=trim((string)($_POST['legal_name']??''));
        if ($legal===''||strcasecmp(preg_replace('/\s+/',' ',$legal),preg_replace('/\s+/',' ',$given))!==0) throw new RuntimeException('Enter your full legal name exactly as recorded in your employee profile.');
        $method=(string)($_POST['signature_method']??''); $data=(string)($_POST['signature_data']??'');
        if ($method==='drawn' && !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#',$data)) throw new RuntimeException('Draw your signature before continuing.');
        if ($method==='typed' && strcasecmp($given,trim((string)($_POST['typed_signature']??'')))!==0) throw new RuntimeException('Type your full legal name as your signature.');
        if (!in_array($method,array('drawn','typed'),true)) throw new RuntimeException('Choose a valid signature method.');
        $a=$db->prepare("SELECT * FROM hr_policy_acknowledgements WHERE version_id=? AND employee_id=? FOR UPDATE");
        $db->beginTransaction(); $a->execute(array($id,$eid)); $ack=$a->fetch(PDO::FETCH_ASSOC);
        if (!$ack||empty($ack['opened_at'])||empty($ack['reached_end_at'])) throw new RuntimeException('Open the policy and reach the acknowledgement section before signing.');
        if (!empty($ack['signed_at'])) {
            $db->rollBack();
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(array(
                    'ok'=>true,
                    'status'=>'Signed & Acknowledged',
                    'signed_at'=>date('c', strtotime((string)$ack['signed_at'])),
                    'receipt_url'=>'policy-receipt.php?id='.$ack['id'],
                    'already_signed'=>true,
                ));
                exit;
            }
            header('Location: policy-receipt.php?id='.$ack['id']); exit;
        }
        $ref='HOP-ACK-'.date('Ymd').'-'.$id.'-'.$eid.'-'.strtoupper(bin2hex(random_bytes(3)));
        $meta=json_encode(array('ip'=>$_SERVER['REMOTE_ADDR']??null,'user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),'session_user_id'=>(int)$user['id']));
        $s=$db->prepare("UPDATE hr_policy_acknowledgements SET legal_name=?,signed_at=NOW(),acknowledged_at=NOW(),acknowledgement_text=?,acknowledgement_text_version=?,signature_method=?,signature_data=?,document_hash=?,evidence_metadata=?,acknowledgement_reference=?,status='signed' WHERE id=? AND signed_at IS NULL");
        $s->execute(array($given,hrPolicyAcknowledgementText(),HR_POLICY_ACK_TEXT_VERSION,$method,$method==='drawn'?$data:$given,$v['document_hash'],$meta,$ref,$ack['id']));
        $db->prepare("UPDATE hr_policy_assignments SET status='acknowledged' WHERE version_id=? AND user_id=?")->execute(array($id,(int)$user['id']));
        $db->prepare("UPDATE hr_policy_notifications SET resolved_at=COALESCE(resolved_at,NOW()),remind_after=NULL WHERE version_id=? AND user_id=?")->execute(array($id,(int)$user['id']));
        hrPolicyAudit($db,'policy_signed',$v['policy_id'],$id,$eid,array('reference'=>$ref,'method'=>$method)); $db->commit();
        if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(array('ok'=>true,'status'=>'Signed & Acknowledged','signed_at'=>date('c'),'receipt_url'=>'policy-receipt.php?id='.$ack['id'])); exit; }
        header('Location: policy-receipt.php?id='.$ack['id']); exit;
    }
    throw new RuntimeException('Unknown policy action.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if (in_array(($action??''),array('mark_end','save_progress'),true) || !empty($_POST['ajax'])) { http_response_code(422); header('Content-Type: application/json'); echo json_encode(array('ok'=>false,'error'=>$e->getMessage())); exit; }
    policyBack($e->getMessage());
}
