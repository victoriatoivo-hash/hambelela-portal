<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/config.php';
require_once BASE_PATH.'/shared/accounts-import-vat.php';

import_vat_require_owner();
import_vat_schema_ready();
header('Cache-Control: no-store');

function im2_reply(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function im2_upload_statement(): array
{
    if (!isset($_FILES['statement']) || !is_uploaded_file((string)$_FILES['statement']['tmp_name'])) {
        throw new RuntimeException('Select a NamRA statement to upload.');
    }
    $file = $_FILES['statement'];
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The NamRA statement did not upload successfully.');
    }
    $tmp = (string)$file['tmp_name'];
    $size = (int)$file['size'];
    if ($size <= 0 || $size > 30 * 1024 * 1024) {
        throw new RuntimeException('Statements must be no larger than 30 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $allowed = [
        'application/pdf' => 'pdf',
        'text/csv' => 'csv',
        'text/plain' => 'csv',
        'application/vnd.ms-excel' => 'csv',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Upload a machine-readable PDF or CSV NamRA statement.');
    }
    $hash = hash_file('sha256', $tmp);
    $find = db()->prepare('SELECT id FROM accounts_import_vat_statements WHERE sha256=?');
    $find->execute([$hash]);
    if ($existing = (int)$find->fetchColumn()) {
        return [
            'duplicate' => true,
            'statement' => import_vat_statement($existing),
            'message' => 'This exact statement was already uploaded. No duplicate records were created.',
        ];
    }

    $directory = BASE_PATH.'/uploads/import-vat-statements';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Protected statement storage is unavailable.');
    }
    $stored = bin2hex(random_bytes(24)).'.'.$allowed[$mime];
    $storedPath = $directory.'/'.$stored;
    if (!move_uploaded_file($tmp, $storedPath)) {
        throw new RuntimeException('The NamRA statement could not be stored.');
    }

    $rows = [];
    $engine = 'csv';
    $status = 'needs_review';
    try {
        if ($allowed[$mime] === 'pdf') {
            $parsed = import_vat_pdf_rows($storedPath);
            $rows = $parsed['rows'];
            $engine = $parsed['engine'];
        } else {
            $rows = import_vat_csv_rows($storedPath);
        }
        $message = count($rows).' NamRA transaction rows detected using '.$engine.'. Review the rows before confirmation. Penalties and interest are retained for audit but excluded from payable totals.';
    } catch (Throwable $error) {
        $status = 'parse_failed';
        $message = $error->getMessage();
    }

    $counts = ['assessment' => 0, 'revision' => 0, 'payment' => 0, 'ignored_penalty' => 0, 'ignored_interest' => 0, 'needs_review' => 0];
    foreach ($rows as $row) {
        $counts[$row['classification']] = ($counts[$row['classification']] ?? 0) + 1;
    }
    $user = current_user();
    db()->beginTransaction();
    try {
        db()->prepare('INSERT INTO accounts_import_vat_statements(original_filename,stored_filename,mime_type,file_size,sha256,statement_period,status,parse_message,rows_detected,liabilities_detected,payments_detected,revision_count,penalty_count,interest_count,needs_review,uploaded_by,uploaded_by_name) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            mb_substr(basename((string)$file['name']), 0, 255), $stored, $mime, $size, $hash,
            $_POST['statement_period'] ?? null, $status, $message, count($rows), $counts['assessment'],
            $counts['payment'], $counts['revision'], $counts['ignored_penalty'], $counts['ignored_interest'],
            $counts['needs_review'], (int)$user['id'], (string)$user['name'],
        ]);
        $statementId = (int)db()->lastInsertId();
        $insert = db()->prepare('INSERT INTO accounts_import_vat_statement_rows(statement_id,source_row_number,transaction_date,due_date,reference,description,debit,credit,import_vat_amount,other_charge_amount,payment_amount,row_kind,confidence,match_status,tax_type,transaction_type,liability_type,doc_number,tax_year,tax_period,effective_date,action_date,transaction_amount,classification,included_in_payable,source_hash,waiver_status,source_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $duplicateCheck = db()->prepare('SELECT r.id FROM accounts_import_vat_statement_rows r JOIN accounts_import_vat_statements s ON s.id=r.statement_id WHERE r.source_hash=? AND s.status<>\'parse_failed\' LIMIT 1');
        foreach ($rows as $row) {
            $duplicateCheck->execute([$row['source_hash']]);
            $matchStatus = $duplicateCheck->fetchColumn() ? 'possible_duplicate' : $row['match_status'];
            $insert->execute([
                $statementId, $row['source_row_number'], $row['transaction_date'], $row['due_date'], $row['reference'],
                $row['description'], $row['debit'], $row['credit'], $row['import_vat_amount'], $row['other_charge_amount'],
                $row['payment_amount'], $row['row_kind'], $row['confidence'], $matchStatus, $row['tax_type'],
                $row['transaction_type'], $row['liability_type'], $row['doc_number'], $row['tax_year'], $row['tax_period'],
                $row['effective_date'], $row['action_date'], $row['transaction_amount'], $row['classification'],
                $row['included_in_payable'], $row['source_hash'], $row['waiver_status'], $row['source_json'],
            ]);
        }
        import_vat_statement_audit($statementId, 'statement_uploaded', [
            'sha256' => $hash, 'rows' => count($rows), 'counts' => $counts, 'mime' => $mime, 'extractor' => $engine,
        ]);
        db()->commit();
        return ['duplicate' => false, 'statement' => import_vat_statement($statementId), 'message' => $message];
    } catch (Throwable $error) {
        db()->rollBack();
        @unlink($storedPath);
        throw $error;
    }
}

function im2_period_reference(string $taxYear, string $taxPeriod): string
{
    return 'NAMRA-'.preg_replace('/[^A-Za-z0-9-]/', '', $taxYear).'-'.preg_replace('/[^A-Za-z0-9-]/', '', $taxPeriod);
}

function im2_confirm_statement(int $statementId): array
{
    $statement = import_vat_statement($statementId);
    if (!$statement) {
        throw new RuntimeException('Statement not found.');
    }
    if ($statement['confirmed_at']) {
        return $statement;
    }

    $periods = [];
    foreach ($statement['rows'] as $row) {
        if ((int)$row['excluded'] || $row['match_status'] === 'possible_duplicate') {
            continue;
        }
        $classification = (string)$row['classification'];
        if (in_array($classification, ['ignored_penalty', 'ignored_interest'], true)) {
            continue;
        }
        if ($classification === 'needs_review' || $row['confidence'] === 'low') {
            throw new RuntimeException('Classify or exclude row '.$row['source_row_number'].' before confirmation.');
        }
        $taxYear = trim((string)$row['tax_year']);
        $taxPeriod = trim((string)$row['tax_period']);
        $month = import_vat_tax_period_month($taxYear, $taxPeriod);
        if (!$month) {
            throw new RuntimeException('Row '.$row['source_row_number'].' has an invalid NamRA Tax Year or Tax Period.');
        }
        $key = $taxYear.'|'.$taxPeriod;
        if (!isset($periods[$key])) {
            $periods[$key] = ['tax_year' => $taxYear, 'tax_period' => $taxPeriod, 'month' => $month, 'principal_rows' => [], 'payments' => []];
        }
        if (in_array($classification, ['assessment', 'revision'], true)) {
            $periods[$key]['principal_rows'][] = $row;
        } elseif ($classification === 'payment') {
            $periods[$key]['payments'][] = $row;
        }
    }
    if (!$periods) {
        throw new RuntimeException('There are no reviewed assessment, revision or VIA payment rows to import.');
    }

    $user = current_user();
    $newRecords = 0;
    $matchedPayments = 0;
    $duplicatesSkipped = 0;
    db()->beginTransaction();
    try {
        $lock = db()->prepare('SELECT confirmed_at FROM accounts_import_vat_statements WHERE id=? FOR UPDATE');
        $lock->execute([$statementId]);
        if ($lock->fetchColumn()) {
            db()->commit();
            return import_vat_statement($statementId);
        }

        foreach ($periods as $period) {
            $reference = im2_period_reference($period['tax_year'], $period['tax_period']);
            $find = db()->prepare('SELECT * FROM accounts_import_vat_liabilities WHERE reference=? AND deleted_at IS NULL FOR UPDATE');
            $find->execute([$reference]);
            $liability = $find->fetch() ?: null;

            $principalQuery = db()->prepare("SELECT SUM(x.transaction_amount) FROM (SELECT r.source_hash,MAX(r.transaction_amount) transaction_amount FROM accounts_import_vat_statement_rows r JOIN accounts_import_vat_statements s ON s.id=r.statement_id WHERE r.tax_year=? AND r.tax_period=? AND r.classification IN ('assessment','revision') AND r.excluded=0 AND r.match_status<>'possible_duplicate' AND (s.status='confirmed' OR s.id=?) GROUP BY r.source_hash) x");
            $principalQuery->execute([$period['tax_year'], $period['tax_period'], $statementId]);
            $principal = round((float)$principalQuery->fetchColumn(), 2);
            $basis = $period['principal_rows'][0] ?? null;

            if (!$liability && $principal <= 0 && !$period['payments']) {
                foreach ($period['principal_rows'] as $row) {
                    db()->prepare("UPDATE accounts_import_vat_statement_rows SET match_status='excluded_no_value',match_method='Zero-value principal retained for audit' WHERE id=?")->execute([(int)$row['id']]);
                }
                continue;
            }
            if (!$liability && $principal <= 0) {
                throw new RuntimeException('VIA payment for Tax Year '.$period['tax_year'].' / Tax Period '.$period['tax_period'].' has no assessment to match. Review the row before confirmation.');
            }
            if (!$liability) {
                if (!$basis || !$basis['due_date'] || !$basis['transaction_date']) {
                    throw new RuntimeException('Tax Year '.$period['tax_year'].' / Tax Period '.$period['tax_period'].' needs an action/effective date and due date.');
                }
                db()->prepare('INSERT INTO accounts_import_vat_liabilities(import_date,import_month,supplier,description,reference,source_system,payment_arrangement,import_vat_amount,duty_amount,other_charge_amount,total_due,due_date,notes,created_by,created_by_name,source_statement_id,source_statement_row_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                    $basis['transaction_date'], $period['month'], '',
                    'NamRA Import VAT principal for '.$period['tax_year'].' / '.$period['tax_period'], $reference,
                    'NamRA Transaction Records', 'import_vat_account', $principal, 0, 0, $principal,
                    $basis['due_date'], 'Principal uses unique 201 assessments plus 204 increased-debit revisions. Codes 481 and 304 are excluded.',
                    (int)$user['id'], (string)$user['name'], $statementId, (int)$basis['id'],
                ]);
                $importId = (int)db()->lastInsertId();
                $newRecords++;
            } else {
                $importId = (int)$liability['id'];
                if ($principal > 0 && abs((float)$liability['total_due'] - $principal) > 0.005) {
                    db()->prepare('UPDATE accounts_import_vat_liabilities SET import_month=?,import_vat_amount=?,total_due=?,updated_by=? WHERE id=?')->execute([$period['month'], $principal, $principal, (int)$user['id'], $importId]);
                    import_vat_audit($importId, 'namra_principal_reconciled', $liability, ['total_due' => $principal, 'statement_id' => $statementId]);
                }
            }

            foreach ($period['principal_rows'] as $row) {
                db()->prepare("UPDATE accounts_import_vat_statement_rows SET matched_import_id=?,match_status='matched',match_method='Tax Year + Tax Period principal' WHERE id=?")->execute([$importId, (int)$row['id']]);
            }
            foreach ($period['payments'] as $payment) {
                $already = db()->prepare("SELECT p.id FROM accounts_import_vat_payments p JOIN accounts_import_vat_statement_rows r ON r.id=p.source_statement_row_id WHERE r.source_hash=? AND p.reversed_at IS NULL LIMIT 1");
                $already->execute([$payment['source_hash']]);
                if ($already->fetchColumn()) {
                    $duplicatesSkipped++;
                    continue;
                }
                db()->prepare('INSERT INTO accounts_import_vat_payments(import_id,payment_date,amount,reference,payment_method,notes,created_by,created_by_name,source_statement_id,source_statement_row_id) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([
                    $importId, $payment['transaction_date'], abs((float)$payment['transaction_amount']),
                    $payment['doc_number'] ?: $payment['reference'], 'NamRA VIA payment',
                    'Matched by exact NamRA Tax Year + Tax Period; amount was not used as the sole key.',
                    (int)$user['id'], (string)$user['name'], $statementId, (int)$payment['id'],
                ]);
                db()->prepare("UPDATE accounts_import_vat_statement_rows SET matched_import_id=?,match_status='matched',match_method='Exact Tax Year + Tax Period' WHERE id=?")->execute([$importId, (int)$payment['id']]);
                $matchedPayments++;
            }
        }

        db()->prepare("UPDATE accounts_import_vat_statements SET status='confirmed',new_records=?,matched_payments=?,duplicates_skipped=?,confirmed_by=?,confirmed_by_name=?,confirmed_at=NOW() WHERE id=? AND confirmed_at IS NULL")->execute([
            $newRecords, $matchedPayments, $duplicatesSkipped, (int)$user['id'], (string)$user['name'], $statementId,
        ]);
        import_vat_statement_audit($statementId, 'statement_confirmed', [
            'new_periods' => $newRecords,
            'matched_payments' => $matchedPayments,
            'duplicates_skipped' => $duplicatesSkipped,
            'payment_match' => 'exact Tax Year + Tax Period',
            'principal_basis' => 'unique 201 + 204 rows',
            'excluded_codes' => ['481', '304'],
        ]);
        db()->commit();
        return import_vat_statement($statementId);
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

$action = (string)($_REQUEST['action'] ?? 'list');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['upload_statement', 'confirm_statement'], true)) {
    try {
        import_vat_verify((string)($_POST['csrf'] ?? ''));
        if ($action === 'upload_statement') {
            $result = im2_upload_statement();
            im2_reply(['ok' => true, 'message' => $result['message'], 'data' => $result]);
        }
        $statement = im2_confirm_statement((int)($_POST['id'] ?? 0));
        im2_reply(['ok' => true, 'message' => 'Statement confirmed. Liabilities and VIA payments were posted once.', 'data' => $statement]);
    } catch (Throwable $error) {
        im2_reply(['ok' => false, 'message' => $error->getMessage()], 400);
    }
}

require BASE_PATH.'/apps/accounts/import-vat-api-legacy.php';
