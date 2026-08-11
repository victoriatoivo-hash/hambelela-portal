<?php
/* Versioned HR policies and immutable employee acknowledgements. */

const HR_POLICY_ACK_TEXT_VERSION = '1.0';

function hrPolicyAcknowledgementText(): string {
    return "EMPLOYEE ACKNOWLEDGEMENT\n\n"
        . "I confirm that I have received access to the Hambelela Organic Employee Handbook & HR Policy, Version 1.0.\n\n"
        . "I confirm that I have read the policy and have been given an opportunity to ask questions about matters I do not understand.\n\n"
        . "I understand the standards, procedures and responsibilities applicable to my employment and agree to comply with lawful company policies and reasonable lawful instructions.\n\n"
        . "I understand that this handbook must be read together with my employment contract and applicable Namibian law.\n\n"
        . "I understand that this acknowledgement does not waive, reduce or remove any right or protection granted to me by applicable law.\n\n"
        . "I understand that Hambelela Organic may lawfully amend its policies from time to time and that material amendments may require me to read and acknowledge a new version.";
}

function hrPolicyEnsureSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policies (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(190) NOT NULL,
        policy_type VARCHAR(30) NOT NULL DEFAULT 'company_policy',
        current_version_id INT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        created_by INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policy_versions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        policy_id INT UNSIGNED NOT NULL,
        version_number VARCHAR(30) NOT NULL,
        title VARCHAR(190) NOT NULL,
        created_date DATE NOT NULL,
        effective_date DATE NOT NULL,
        acknowledgement_deadline DATE NULL,
        next_review VARCHAR(80) NULL,
        file_path VARCHAR(500) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        document_hash CHAR(64) NOT NULL,
        acknowledgement_required TINYINT(1) NOT NULL DEFAULT 0,
        acknowledgement_text_version VARCHAR(30) NOT NULL DEFAULT '1.0',
        changes_summary TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        created_by INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        published_by INT UNSIGNED NULL,
        published_at DATETIME NULL,
        published_by_name VARCHAR(190) NULL,
        employees_assigned INT UNSIGNED NULL,
        notifications_created INT UNSIGNED NULL,
        superseded_at DATETIME NULL,
        digital_html MEDIUMTEXT NULL,
        digital_hash CHAR(64) NULL,
        digital_generated_at DATETIME NULL,
        UNIQUE KEY policy_version (policy_id, version_number),
        KEY policy_status (policy_id, status),
        KEY effective_date (effective_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policy_acknowledgements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        policy_id INT UNSIGNED NOT NULL,
        version_id INT UNSIGNED NOT NULL,
        employee_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        legal_name VARCHAR(190) NULL,
        opened_at DATETIME NULL,
        last_opened_at DATETIME NULL,
        reading_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        reading_position INT UNSIGNED NOT NULL DEFAULT 0,
        reached_end_at DATETIME NULL,
        signed_at DATETIME NULL,
        acknowledged_at DATETIME NULL,
        acknowledgement_text MEDIUMTEXT NULL,
        acknowledgement_text_version VARCHAR(30) NULL,
        signature_method VARCHAR(20) NULL,
        signature_data MEDIUMTEXT NULL,
        document_hash CHAR(64) NULL,
        evidence_metadata TEXT NULL,
        acknowledgement_reference VARCHAR(80) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'opened',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY employee_version (employee_id, version_id),
        UNIQUE KEY acknowledgement_reference (acknowledgement_reference),
        KEY version_status (version_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policy_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actor_user_id INT UNSIGNED NULL,
        actor_employee_id INT UNSIGNED NULL,
        policy_id INT UNSIGNED NULL,
        version_id INT UNSIGNED NULL,
        subject_employee_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL,
        details TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY policy_event (policy_id, version_id, created_at),
        KEY employee_event (subject_employee_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policy_notifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        version_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        notification_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY version_user (version_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policy_assignments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        policy_id INT UNSIGNED NOT NULL,
        version_id INT UNSIGNED NOT NULL,
        employee_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(30) NOT NULL DEFAULT 'assigned',
        UNIQUE KEY employee_version (employee_id, version_id),
        KEY version_status (version_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    hrPolicyAddColumn($db, 'hr_policy_versions', 'digital_html', 'MEDIUMTEXT NULL');
    hrPolicyAddColumn($db, 'hr_policy_versions', 'digital_hash', 'CHAR(64) NULL');
    hrPolicyAddColumn($db, 'hr_policy_versions', 'digital_generated_at', 'DATETIME NULL');
    hrPolicyAddColumn($db, 'hr_policy_versions', 'published_by_name', 'VARCHAR(190) NULL');
    hrPolicyAddColumn($db, 'hr_policy_versions', 'employees_assigned', 'INT UNSIGNED NULL');
    hrPolicyAddColumn($db, 'hr_policy_versions', 'notifications_created', 'INT UNSIGNED NULL');
    hrPolicyAddColumn($db, 'hr_policy_acknowledgements', 'last_opened_at', 'DATETIME NULL');
    hrPolicyAddColumn($db, 'hr_policy_acknowledgements', 'reading_percent', 'DECIMAL(5,2) NOT NULL DEFAULT 0');
    hrPolicyAddColumn($db, 'hr_policy_acknowledgements', 'reading_position', 'INT UNSIGNED NOT NULL DEFAULT 0');
}

function hrPolicyAddColumn(PDO $db, string $table, string $column, string $definition): void {
    $q=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute(array($table,$column));
    if (!(int)$q->fetchColumn()) $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function hrPolicyXmlText(DOMNode $node): string {
    $value='';
    foreach ($node->childNodes as $child) $value.=$child->nodeType===XML_TEXT_NODE ? $child->nodeValue : hrPolicyXmlText($child);
    return trim(preg_replace('/\s+/u',' ',$value));
}

function hrPolicyDocxDigitalHtml(string $path): array {
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) throw new RuntimeException('The server cannot create the required digital policy viewer from this DOCX file.');
    $zip=new ZipArchive();
    if ($zip->open($path)!==true) throw new RuntimeException('The approved DOCX file could not be opened for digital conversion.');
    $xml=$zip->getFromName('word/document.xml'); $zip->close();
    if ($xml===false) throw new RuntimeException('The approved DOCX file has no readable document body.');
    $dom=new DOMDocument();
    if (!@$dom->loadXML($xml,LIBXML_NONET|LIBXML_COMPACT)) throw new RuntimeException('The approved DOCX body could not be parsed.');
    $xp=new DOMXPath($dom); $xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $body=$xp->query('//w:body')->item(0); $html=''; $toc=array(); $listOpen=false; $headingIndex=0;
    foreach ($body->childNodes as $node) {
        if ($node->localName==='p') {
            $text=hrPolicyXmlText($node); if ($text==='') continue;
            $styleNode=$xp->query('./w:pPr/w:pStyle',$node)->item(0); $style=$styleNode ? (string)$styleNode->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','val') : '';
            $isList=stripos($style,'List')!==false;
            if ($isList && !$listOpen) {$html.='<ul>'; $listOpen=true;}
            if (!$isList && $listOpen) {$html.='</ul>'; $listOpen=false;}
            $safe=htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            if (stripos($style,'Title')!==false) $html.='<h1 class="policy-document-title">'.$safe.'</h1>';
            elseif (preg_match('/Heading\s*1|Heading1/i',$style)) { $headingIndex++; $id='policy-section-'.$headingIndex; $toc[]=array('id'=>$id,'text'=>$text); $html.='<h2 id="'.$id.'">'.$safe.'</h2>'; }
            elseif (preg_match('/Heading\s*2|Heading2/i',$style)) $html.='<h3>'.$safe.'</h3>';
            elseif ($isList) $html.='<li>'.$safe.'</li>';
            elseif ($text==='HAMBELELA ORGANIC') $html.='<p class="policy-brand">'.$safe.'</p>';
            else $html.='<p>'.$safe.'</p>';
        } elseif ($node->localName==='tbl') {
            if ($listOpen) {$html.='</ul>'; $listOpen=false;}
            $html.='<div class="policy-digital-table"><table><tbody>';
            foreach ($xp->query('./w:tr',$node) as $row) {
                $html.='<tr>';
                foreach ($xp->query('./w:tc',$row) as $cell) $html.='<td>'.htmlspecialchars(hrPolicyXmlText($cell),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</td>';
                $html.='</tr>';
            }
            $html.='</tbody></table></div>';
        }
    }
    if ($listOpen) $html.='</ul>';
    if (!$toc || strlen(strip_tags($html))<1000) throw new RuntimeException('Digital policy conversion produced incomplete content. The draft was not saved.');
    return array('html'=>$html,'toc'=>$toc,'hash'=>hash('sha256',$html));
}

function hrPolicyCsrf(): string {
    if (empty($_SESSION['hr_policy_csrf'])) $_SESSION['hr_policy_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['hr_policy_csrf'];
}

function hrPolicyVerifyCsrf(string $token): void {
    if (!hash_equals(hrPolicyCsrf(), $token)) throw new RuntimeException('Your session token expired. Refresh the page and try again.');
}

function hrPolicyPrivateDir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'policies';
}

function hrPolicyAudit(PDO $db, string $action, ?int $policyId, ?int $versionId, ?int $employeeId, array $details = array()): void {
    $u = currentUser();
    $stmt = $db->prepare("INSERT INTO hr_policy_audit (actor_user_id,actor_employee_id,policy_id,version_id,subject_employee_id,action,details) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute(array($u ? (int)$u['id'] : null, $u && !empty($u['emp_id']) ? (int)$u['emp_id'] : null, $policyId, $versionId, $employeeId, $action, json_encode($details)));
}

function hrPolicyEmployeeId(array $user): int {
    return !empty($user['emp_id']) ? (int)$user['emp_id'] : 0;
}

function hrPolicyLegalName(PDO $db, int $employeeId): string {
    $s = $db->prepare("SELECT TRIM(CONCAT(first_name,' ',last_name)) FROM employees WHERE id=? LIMIT 1");
    $s->execute(array($employeeId));
    return trim((string)$s->fetchColumn());
}

function hrPolicyVersion(PDO $db, int $id): ?array {
    $s=$db->prepare("SELECT v.*,p.policy_type,p.current_version_id FROM hr_policy_versions v JOIN hr_policies p ON p.id=v.policy_id WHERE v.id=?");
    $s->execute(array($id)); $row=$s->fetch(PDO::FETCH_ASSOC); return $row ?: null;
}

function hrPolicyDisplayStatus(array $v): string {
    if ($v['status']==='draft') return 'Draft';
    if ($v['status']==='ready_to_publish') return 'Ready to Publish';
    if ($v['status']==='superseded') return 'Superseded';
    if ($v['status']==='archived') return 'Archived';
    return date('Y-m-d') < $v['effective_date'] ? 'Published — Effective ' . date('j F Y', strtotime($v['effective_date'])) : 'Current — In Force';
}

function hrPolicyMetadataMismatches(array $v): array {
    $issues=array(); $html=(string)($v['digital_html']??''); $plain=html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8');
    if (stripos($plain,'Effective Date Pending owner approval')!==false || stripos($plain,'Pending owner approval')!==false) {
        $issues[]='Original rendered cover says “Effective Date: Pending owner approval”; portal metadata says “Effective Date: '.date('j F Y',strtotime($v['effective_date'])).'”.';
    }
    if ($v['version_number']==='' || stripos($plain,'Version '.$v['version_number'])===false) $issues[]='Digital policy version does not match portal Version '.$v['version_number'].'.';
    if (empty($v['document_hash']) || !preg_match('/^[a-f0-9]{64}$/i',(string)$v['document_hash'])) $issues[]='Original source document hash is missing or invalid.';
    if (empty($v['digital_hash']) || !preg_match('/^[a-f0-9]{64}$/i',(string)$v['digital_hash'])) $issues[]='Rendered digital document hash is missing or invalid.';
    if (!empty($v['acknowledgement_required']) && empty($v['acknowledgement_deadline'])) $issues[]='Acknowledgement is required but no deadline is set.';
    return $issues;
}

function hrPolicyPending(PDO $db, int $employeeId): array {
    $s=$db->prepare("SELECT v.*,p.title AS policy_title,a.opened_at,a.signed_at
      FROM hr_policy_versions v JOIN hr_policies p ON p.id=v.policy_id
      LEFT JOIN hr_policy_acknowledgements a ON a.version_id=v.id AND a.employee_id=?
      WHERE v.status='published' AND v.acknowledgement_required=1 AND a.signed_at IS NULL
      ORDER BY v.acknowledgement_deadline IS NULL,v.acknowledgement_deadline,v.id");
    $s->execute(array($employeeId)); return $s->fetchAll(PDO::FETCH_ASSOC);
}

function hrPolicyAckStatus(array $version, ?array $ack): string {
    if ($ack && !empty($ack['signed_at'])) return 'Signed & Acknowledged';
    if (!empty($version['acknowledgement_deadline']) && date('Y-m-d') > $version['acknowledgement_deadline']) return 'Overdue — Acknowledgement Required';
    if ($ack && !empty($ack['opened_at'])) return 'In Progress — Signature Pending';
    return 'Not Opened';
}
