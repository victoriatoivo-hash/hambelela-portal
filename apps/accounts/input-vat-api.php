<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/employee-features.php';
require_once BASE_PATH.'/shared/accounts-input-vat.php';

accounts_require_input_vat_access();
accounts_input_vat_schema_ready();
header('Cache-Control: no-store');

function iv_reply(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function iv_uploads(int $purchaseId): array
{
    if (empty($_FILES['files']['name'])) return [];

    $names = (array) $_FILES['files']['name'];
    if (count($names) > 10) throw new RuntimeException('Upload no more than 10 files at once.');

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/csv' => 'csv',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    $dir = BASE_PATH.'/uploads/input-vat';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Protected attachment storage is unavailable.');

    $saved = [];

    foreach ($names as $i => $name) {
        if ((int) ($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('An attachment did not upload successfully.');
        $tmp = (string) $_FILES['files']['tmp_name'][$i];
        $size = (int) ($_FILES['files']['size'][$i] ?? 0);
        if (!is_uploaded_file($tmp) || $size <= 0 || $size > 25 * 1024 * 1024) throw new RuntimeException('Each attachment must be between 1 byte and 25 MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!isset($allowed[$mime])) throw new RuntimeException('That attachment type is not allowed.');

        $stored = bin2hex(random_bytes(24)).'.'.$allowed[$mime];
        if (!move_uploaded_file($tmp, $dir.'/'.$stored)) throw new RuntimeException('An attachment could not be stored.');

        try {
            $stmt = db()->prepare('INSERT INTO accounts_input_vat_attachments(purchase_id,original_filename,stored_filename,mime_type,file_size,uploaded_by)VALUES(?,?,?,?,?,?)');
            $stmt->execute([
                $purchaseId,
                mb_substr(basename(str_replace('\\', '/', (string) $name)), 0, 255),
                $stored,
                $mime,
                $size,
                (int) (current_user()['id'] ?? 0),
            ]);
            $saved[] = $stored;
        } catch (Throwable $e) {
            @unlink($dir.'/'.$stored);
            throw $e;
        }
    }

    return $saved;
}

function iv_month_from_request(string $value): string
{
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : date('Y-m');
}

function iv_valid_purchase_date(string $value): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) return false;
    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

function iv_require_history_access(): void
{
    if (accounts_can('input_vat.history')) return;
    iv_reply(['ok' => false, 'error' => 'Transaction History is available to Owner/Admin only.'], 403);
}

function iv_duplicate_matches(int $id, string $date, string $supplier, float $inclusive, string $reference): array
{
    $sql = 'SELECT * FROM accounts_input_vat_purchases WHERE deleted_at IS NULL AND id<>? AND purchase_date=? AND LOWER(TRIM(supplier))=LOWER(TRIM(?)) AND amount_incl_vat=?';
    $params = [$id, $date, $supplier, round($inclusive, 2)];
    if ($reference !== '') { $sql .= ' AND LOWER(TRIM(COALESCE(invoice_reference,\'\')))=LOWER(TRIM(?))'; $params[] = $reference; }
    $stmt = db()->prepare($sql.' ORDER BY id DESC LIMIT 10'); $stmt->execute($params);
    return array_map('accounts_purchase_payload', $stmt->fetchAll());
}

function iv_capture_progress(): array
{
    $start = accounts_historical_capture_start_date(); $cursor = substr($start, 0, 7); $end = date('Y-m'); $result = [];
    $countStmt=db()->prepare("SELECT DATE_FORMAT(purchase_date,'%Y-%m') month_key,COUNT(*) record_count,SUM(amount_incl_vat) total_incl,SUM(vat_amount) total_vat FROM accounts_input_vat_purchases WHERE deleted_at IS NULL AND purchase_date>=? AND purchase_date<? GROUP BY DATE_FORMAT(purchase_date,'%Y-%m')");
    $countStmt->execute([$start,date('Y-m-d',strtotime($end.'-01 +1 month'))]);$counts=$countStmt->fetchAll();
    $countMap=[]; foreach($counts as $row) $countMap[(string)$row['month_key']]=$row;
    $statuses=db()->query('SELECT * FROM accounts_input_vat_month_status')->fetchAll(); $statusMap=[]; foreach($statuses as $row) $statusMap[(string)$row['month_key']]=$row;
    while ($cursor <= $end) {
        $row=$countMap[$cursor]??[]; $status=$statusMap[$cursor]??[];
        $complete=(bool)($status['capture_complete']??false); $count=(int)($row['record_count']??0);
        $result[]=['month'=>$cursor,'count'=>$count,'inclusive'=>(float)($row['total_incl']??0),'vat'=>(float)($row['total_vat']??0),'complete'=>$complete,'capture_status'=>$complete?'complete':($count>0?'in_progress':'not_started'),'updated_by'=>(string)($status['updated_by_name']??'')];
        $cursor=date('Y-m', strtotime($cursor.'-01 +1 month'));
    }
    return $result;
}

try {
    $action = (string) ($_REQUEST['action'] ?? 'list');

    if ($action === 'export') {
        $month = iv_month_from_request((string) ($_GET['month'] ?? ''));
        $history = ($_GET['period'] ?? '') === 'history';
        if ($history) iv_require_history_access();
        $all = accounts_can('input_vat.history') && ($_GET['period'] ?? '') === 'all';
        $where = 'deleted_at IS NULL';
        $params = [];

        if ($history) {
            $dateFrom=trim((string)($_GET['date_from']??'')); $dateTo=trim((string)($_GET['date_to']??''));
            $historyMonth=trim((string)($_GET['history_month']??''));
            if (preg_match('/^\d{4}-\d{2}$/',$historyMonth)) { $monthFrom=$historyMonth.'-01'; $where.=' AND purchase_date>=? AND purchase_date<?'; $params[]=$monthFrom; $params[]=date('Y-m-d',strtotime($monthFrom.' +1 month')); }
            if (iv_valid_purchase_date($dateFrom)) { $where.=' AND purchase_date>=?'; $params[]=$dateFrom; }
            if (iv_valid_purchase_date($dateTo)) { $where.=' AND purchase_date<=?'; $params[]=$dateTo; }
        } elseif (!$all) {
            $where .= ' AND purchase_date>=? AND purchase_date<?';
            $from = $month.'-01';
            $params = [$from, date('Y-m-d', strtotime($from.' +1 month'))];
        }

        $search=trim((string)($_GET['search']??''));
        if ($search!=='') { $where.=' AND (supplier LIKE ? OR description LIKE ? OR invoice_reference LIKE ?)'; $params[]='%'.$search.'%'; $params[]='%'.$search.'%'; $params[]='%'.$search.'%'; }
        $status=trim((string)($_GET['status']??''));
        if (in_array($status,['captured','reviewed','needs_correction'],true)) { $where.=' AND review_status=?'; $params[]=$status; }
        if ($history) {
            $enteredBy=trim((string)($_GET['entered_by']??''));
            if ($enteredBy!=='') { $where.=' AND created_by_name LIKE ?'; $params[]='%'.$enteredBy.'%'; }
            $manual=(string)($_GET['manual']??'');
            if ($manual==='0'||$manual==='1') { $where.=' AND manual_override=?'; $params[]=(int)$manual; }
        }
        $stmt = db()->prepare('SELECT purchase_date,supplier,invoice_reference,description,amount_incl_vat,vat_amount,amount_excl_vat,vat_treatment,review_status,created_by_name,created_at FROM accounts_input_vat_purchases WHERE '.$where.' ORDER BY purchase_date,id');
        $stmt->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        $filename = ($all||$history) ? 'input-vat-transaction-history' : 'hambelela-input-vat-'.$month;
        header('Content-Disposition: attachment; filename="'.$filename.'.csv"');

        $out = fopen('php://output', 'wb');
        fputcsv($out, ['Purchase Date', 'Supplier', 'Reference', 'Description', 'Incl VAT', 'Input VAT', 'Excl VAT', 'VAT Treatment', 'Review Status', 'Entered By', 'Captured At']);
        foreach ($stmt->fetchAll() as $r) fputcsv($out, $r);
        fclose($out);
        exit;
    }

    if ($action === 'audit') {
        if (!accounts_can('input_vat.history')) throw new RuntimeException('You do not have access to audit history.');
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = db()->prepare('SELECT action_key,actor_name,created_at,before_json,after_json FROM accounts_input_vat_audit WHERE purchase_id=? ORDER BY id DESC');
        $stmt->execute([$id]);
        iv_reply(['ok' => true, 'history' => $stmt->fetchAll()]);
    }

    if ($action === 'list') {
        $month = iv_month_from_request((string) ($_GET['month'] ?? ''));
        $history = ($_GET['period'] ?? '') === 'history';
        if ($history) iv_require_history_access();
        $all = accounts_can('input_vat.history') && ($_GET['period'] ?? '') === 'all';
        $where = ['deleted_at IS NULL'];
        $params = [];

        if ($history) {
            $dateFrom=trim((string)($_GET['date_from']??'')); $dateTo=trim((string)($_GET['date_to']??''));
            $historyMonth=trim((string)($_GET['history_month']??''));
            if (preg_match('/^\d{4}-\d{2}$/',$historyMonth)) { $monthFrom=$historyMonth.'-01'; $where[]='purchase_date>=? AND purchase_date<?'; $params[]=$monthFrom; $params[]=date('Y-m-d',strtotime($monthFrom.' +1 month')); }
            if (iv_valid_purchase_date($dateFrom)) { $where[]='purchase_date>=?'; $params[]=$dateFrom; }
            if (iv_valid_purchase_date($dateTo)) { $where[]='purchase_date<=?'; $params[]=$dateTo; }
        } elseif (!$all) {
            $where[] = 'purchase_date>=? AND purchase_date<?';
            $from = $month.'-01';
            $params = [$from, date('Y-m-d', strtotime($from.' +1 month'))];
        }

        $search = trim((string) ($_GET['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(supplier LIKE ? OR description LIKE ? OR invoice_reference LIKE ?)';
            $params[] = '%'.$search.'%';
            $params[] = '%'.$search.'%';
            $params[] = '%'.$search.'%';
        }

        $status = trim((string) ($_GET['status'] ?? ''));
        if (in_array($status, ['captured', 'reviewed', 'needs_correction'], true)) {
            $where[] = 'review_status=?';
            $params[] = $status;
        }

        if ($history) {
            $enteredBy=trim((string)($_GET['entered_by']??''));
            if ($enteredBy!=='') { $where[]='created_by_name LIKE ?'; $params[]='%'.$enteredBy.'%'; }
            $manual=(string)($_GET['manual']??'');
            if ($manual==='0'||$manual==='1') { $where[]='manual_override=?'; $params[]=(int)$manual; }
        }

        $sort = in_array($_GET['sort'] ?? '', ['supplier', 'purchase_date'], true) ? (string) $_GET['sort'] : 'purchase_date';
        $direction = (($_GET['direction'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
        $stmt = db()->prepare('SELECT * FROM accounts_input_vat_purchases WHERE '.implode(' AND ', $where).' ORDER BY '.$sort.' '.$direction.',id DESC');
        $stmt->execute($params);

        $rawRows=$stmt->fetchAll();$attachmentsByPurchase=[];$ids=array_map(static function(array $row): int{return (int)$row['id'];},$rawRows);
        if($ids){$placeholders=implode(',',array_fill(0,count($ids),'?'));$attachmentStmt=db()->prepare('SELECT * FROM accounts_input_vat_attachments WHERE deleted_at IS NULL AND purchase_id IN ('.$placeholders.') ORDER BY purchase_id,id');$attachmentStmt->execute($ids);foreach($attachmentStmt->fetchAll() as $attachment)$attachmentsByPurchase[(int)$attachment['purchase_id']][]=$attachment;}
        $rows=[];foreach($rawRows as $row)$rows[]=accounts_purchase_payload($row,$attachmentsByPurchase[(int)$row['id']]??[]);

        $summary = [
            'count' => count($rows),
            'inclusive' => 0,
            'vat' => 0,
            'exclusive' => 0,
            'treatments' => [],
            'suppliers' => [],
            'descriptions' => [],
        ];

        foreach ($rows as $r) {
            $summary['inclusive'] += $r['inclusive'];
            $summary['vat'] += $r['vat'];
            $summary['exclusive'] += $r['exclusive'];
            if ($r['vat_treatment'] === 'mixed') {
                $summary['treatments']['standard_rated_value'] = ($summary['treatments']['standard_rated_value'] ?? 0) + $r['standard_rated_exclusive'];
                $summary['treatments']['zero_rated_value'] = ($summary['treatments']['zero_rated_value'] ?? 0) + $r['zero_rated_amount'];
            } elseif ($r['vat_treatment'] === 'standard' || $r['vat_treatment'] === 'manual_vat' || $r['vat_treatment'] === 'review_required') {
                $summary['treatments']['standard_rated_value'] = ($summary['treatments']['standard_rated_value'] ?? 0) + $r['exclusive'];
            } elseif ($r['vat_treatment'] === 'zero_rated') {
                $summary['treatments']['zero_rated_value'] = ($summary['treatments']['zero_rated_value'] ?? 0) + $r['exclusive'];
            } else {
                $summary['treatments']['no_vat_non_vat_value'] = ($summary['treatments']['no_vat_non_vat_value'] ?? 0) + $r['exclusive'];
            }
            $summary['suppliers'][$r['supplier']] = ($summary['suppliers'][$r['supplier']] ?? 0) + $r['inclusive'];
            $key = mb_substr($r['description'], 0, 50);
            $summary['descriptions'][$key] = ($summary['descriptions'][$key] ?? 0) + $r['inclusive'];
        }

        iv_reply(['ok' => true, 'view'=>$history?'history':'monthly', 'rows' => $rows, 'summary' => $summary, 'standard_vat_rate' => accounts_standard_vat_rate(), 'historical_capture_start_date'=>accounts_historical_capture_start_date(), 'capture_progress'=>iv_capture_progress(), 'refreshed_at' => date(DATE_ATOM)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST required.');
    accounts_verify_csrf((string) ($_POST['csrf'] ?? ''));

    if ($action === 'save_rate') {
        if (!accounts_is_owner()) throw new RuntimeException('Only the owner can change the VAT rate.');
        $raw = trim((string) ($_POST['rate'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) throw new RuntimeException('Enter a valid VAT rate.');
        $rate = round((float) $raw, 2);
        if ($rate < 0 || $rate > 100) throw new RuntimeException('VAT rate must be between 0% and 100%.');

        $old = accounts_standard_vat_rate();
        if ((float) number_format((float) $old, 2, '.', '') === $rate) {
            $effectiveFrom = date(DATE_ATOM);
            iv_reply(['ok' => true, 'rate' => $rate, 'old_rate' => $old, 'effective_from' => $effectiveFrom, 'message' => 'Standard VAT rate is already set to this value.']);
        }

        $user = current_user();
        $effectiveFrom = date(DATE_ATOM);
        db()->beginTransaction();
        try {
            db()->prepare("INSERT INTO accounts_settings(setting_key,setting_value,updated_by)VALUES('standard_vat_rate',?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)")->execute([(string) $rate, (int) ($user['id'] ?? 0)]);
            db()->prepare('INSERT INTO accounts_settings_audit(setting_key,old_value,new_value,changed_by,changed_by_name,effective_from)VALUES(?,?,?,?,?,NOW())')->execute([
                'standard_vat_rate',
                (string) $old,
                (string) $rate,
                (int) ($user['id'] ?? 0),
                (string) ($user['name'] ?? 'Owner'),
            ]);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        iv_reply(['ok' => true, 'rate' => $rate, 'old_rate' => $old, 'effective_from' => date(DATE_ATOM), 'message' => 'Standard VAT rate updated. Existing records were not recalculated.']);
    }

    if ($action === 'save_capture_settings') {
        if (!accounts_is_owner()) throw new RuntimeException('Only the owner can change historical capture settings.');
        $date=(string)($_POST['historical_capture_start_date']??'');
        if (!iv_valid_purchase_date($date) || $date>date('Y-m-d')) throw new RuntimeException('Choose a valid historical capture start date on or before today.');
        $old=accounts_historical_capture_start_date(); $user=current_user();
        db()->prepare("INSERT INTO accounts_settings(setting_key,setting_value,updated_by)VALUES('historical_capture_start_date',?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)")->execute([$date,(int)($user['id']??0)]);
        db()->prepare('INSERT INTO accounts_settings_audit(setting_key,old_value,new_value,changed_by,changed_by_name,effective_from)VALUES(?,?,?,?,?,NOW())')->execute(['historical_capture_start_date',$old,$date,(int)($user['id']??0),(string)($user['name']??'Owner')]);
        iv_reply(['ok'=>true,'historical_capture_start_date'=>$date,'message'=>'Historical capture start date updated.']);
    }

    if ($action === 'set_month_complete') {
        if (!accounts_is_owner()) throw new RuntimeException('Only the owner can change month capture status.');
        $month=iv_month_from_request((string)($_POST['month']??'')); $complete=(int)($_POST['complete']??0)===1; $user=current_user();
        db()->prepare('INSERT INTO accounts_input_vat_month_status(month_key,capture_complete,updated_by,updated_by_name)VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE capture_complete=VALUES(capture_complete),updated_by=VALUES(updated_by),updated_by_name=VALUES(updated_by_name)')->execute([$month,$complete?1:0,(int)($user['id']??0),(string)($user['name']??'Owner')]);
        iv_reply(['ok'=>true,'message'=>$complete?'Month marked Capture Complete.':'Month reopened for capture.']);
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && !accounts_can('input_vat.edit')) throw new RuntimeException('You do not have permission to edit Input VAT purchases.');
        if ($id <= 0 && !accounts_can('input_vat.create')) throw new RuntimeException('You do not have permission to add Input VAT purchases.');
        $date = (string) ($_POST['purchase_date'] ?? '');
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $reference = trim((string) ($_POST['invoice_reference'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if (!iv_valid_purchase_date($date) || $supplier === '' || $description === '') {
            throw new RuntimeException('Date, supplier and description are required.');
        }

        $start=accounts_historical_capture_start_date();
        if ($date<$start) throw new RuntimeException('Purchase date cannot be earlier than the historical capture start date ('.$start.').');
        if ($date>date('Y-m-d')) throw new RuntimeException('Future purchase dates are not allowed.');
        $source=in_array($_POST['calculation_source']??'', ['inclusive','exclusive'], true)?(string)$_POST['calculation_source']:'inclusive';
        $amount=(float)($source==='exclusive'?($_POST['exclusive_source']??0):($_POST['inclusive']??0));
        $treatment=in_array($_POST['vat_treatment']??'', ['standard','zero_rated','no_vat','mixed','manual_vat','review_required'], true)?(string)$_POST['vat_treatment']:'standard';
        $zeroRatedAmount=round((float)($_POST['zero_rated_amount']??0),2);
        if ($zeroRatedAmount < 0) throw new RuntimeException('Zero-rated amount must be zero or greater.');
        $baseline=accounts_vat_calculate_from_source($amount,$source,$treatment,$zeroRatedAmount);
        $calc=$baseline; $manualOverride=(int)($_POST['manual_override']??0)===1; $overrideReason=trim((string)($_POST['override_reason']??''));
        if ($manualOverride) {
            if ($overrideReason==='') throw new RuntimeException('Select or enter a reason for the manual VAT adjustment.');
            $manualVat=round((float)($_POST['manual_vat']??-1),2); $manualExclusive=round((float)($_POST['manual_exclusive']??-1),2);
            if ($manualVat<0 || $manualExclusive<0) throw new RuntimeException('Enter valid adjusted VAT and excl VAT amounts.');
            $calc['vat']=$manualVat; $calc['exclusive']=$manualExclusive; $calc['inclusive']=round($manualVat+$manualExclusive,2);
        }

        if ($calc['inclusive'] <= 0) throw new RuntimeException('Amount incl VAT must be greater than zero.');
        $duplicates=iv_duplicate_matches($id,$date,$supplier,$calc['inclusive'],$reference);
        if ($duplicates && (int)($_POST['duplicate_confirmed']??0)!==1) iv_reply(['ok'=>false,'code'=>'possible_duplicate','error'=>'Possible duplicate purchase found. Review it before saving.','matches'=>$duplicates],409);

        $user = current_user();
        $before = null;
        db()->beginTransaction();

        try {
            if ($id) {
                $before = accounts_purchase($id);
                if (!$before || !accounts_can_edit_purchase($before)) throw new RuntimeException('You cannot edit this purchase.');
                $stmt = db()->prepare('UPDATE accounts_input_vat_purchases SET purchase_date=?,supplier=?,invoice_reference=?,description=?,notes=?,amount_incl_vat=?,vat_rate=?,vat_rate_used=?,vat_amount=?,automatic_vat_amount=?,amount_excl_vat=?,automatic_excl_vat=?,standard_rated_incl_vat=?,standard_rated_excl_vat=?,zero_rated_amount=?,vat_treatment=?,calculation_source=?,manual_override=?,override_reason=?,override_by=?,override_by_name=?,override_at=?,historical_back_capture=?,updated_by=? WHERE id=?');
                $stmt->execute([
                    $date,$supplier,$reference,$description,trim((string)($_POST['notes']??'')),$calc['inclusive'],$baseline['rate'],$baseline['rate'],$calc['vat'],$baseline['vat'],$calc['exclusive'],$baseline['exclusive'],$baseline['standard_rated_inclusive'],$baseline['standard_rated_exclusive'],$baseline['zero_rated_amount'],$baseline['treatment'],$source,$manualOverride?1:0,$manualOverride?$overrideReason:null,$manualOverride?(int)$user['id']:null,$manualOverride?(string)$user['name']:null,$manualOverride?date('Y-m-d H:i:s'):null,$date<date('Y-m-01')?1:0,(int)$user['id'],$id,
                ]);
            } else {
                $stmt = db()->prepare('INSERT INTO accounts_input_vat_purchases(purchase_date,supplier,invoice_reference,description,notes,amount_incl_vat,vat_rate,vat_rate_used,vat_amount,automatic_vat_amount,amount_excl_vat,automatic_excl_vat,standard_rated_incl_vat,standard_rated_excl_vat,zero_rated_amount,vat_treatment,calculation_source,manual_override,override_reason,override_by,override_by_name,override_at,historical_back_capture,created_by,created_by_name)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([
                    $date,$supplier,$reference,$description,trim((string)($_POST['notes']??'')),$calc['inclusive'],$baseline['rate'],$baseline['rate'],$calc['vat'],$baseline['vat'],$calc['exclusive'],$baseline['exclusive'],$baseline['standard_rated_inclusive'],$baseline['standard_rated_exclusive'],$baseline['zero_rated_amount'],$baseline['treatment'],$source,$manualOverride?1:0,$manualOverride?$overrideReason:null,$manualOverride?(int)$user['id']:null,$manualOverride?(string)$user['name']:null,$manualOverride?date('Y-m-d H:i:s'):null,$date<date('Y-m-01')?1:0,(int)$user['id'],(string)$user['name'],
                ]);
                $id = (int) db()->lastInsertId();
            }

            iv_uploads($id);
            $after = accounts_purchase($id);
            accounts_audit($id, $before ? 'updated' : 'created', $before, $after);
            db()->commit();
            iv_reply(['ok' => true, 'row' => accounts_purchase_payload($after), 'message' => $before ? 'Purchase updated.' : 'Purchase saved.']);
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
    }

    if ($action === 'delete') {
        if (!accounts_can_manage_input_vat_entries()) throw new RuntimeException('You do not have permission to delete Input VAT entries.');
        $id = (int) ($_POST['id'] ?? 0);
        $before = accounts_purchase($id);
        if (!$before) throw new RuntimeException('Purchase not found.');
        if (!accounts_can_delete_purchase($before)) throw new RuntimeException('You do not have permission to delete this purchase.');

        db()->prepare('UPDATE accounts_input_vat_purchases SET deleted_at=NOW(),deleted_by=? WHERE id=? AND deleted_at IS NULL')->execute([
            (int) (current_user()['id'] ?? 0),
            $id,
        ]);

        accounts_audit($id, 'deleted', $before, accounts_purchase($id, true));
        iv_reply(['ok' => true, 'message' => 'Purchase moved to audit history.']);
    }

    if ($action === 'delete_attachment') {
        if (!accounts_can_manage_input_vat_entries()) throw new RuntimeException('You do not have permission to remove attachments.');
        $id = (int) ($_POST['attachment_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM accounts_input_vat_attachments WHERE id=? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $file = $stmt->fetch();
        if (!$file) throw new RuntimeException('Attachment not found.');

        db()->prepare('UPDATE accounts_input_vat_attachments SET deleted_at=NOW(),deleted_by=? WHERE id=?')->execute([
            (int) (current_user()['id'] ?? 0),
            $id,
        ]);

        accounts_audit((int) $file['purchase_id'], 'attachment_deleted', $file, null);
        iv_reply(['ok' => true, 'message' => 'Attachment removed.']);
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    error_log('Input VAT request failed: '.$e->getMessage());
    iv_reply(['ok' => false, 'error' => $e->getMessage()], 400);
}
