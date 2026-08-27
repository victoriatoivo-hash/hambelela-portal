<?php

declare(strict_types=1);

require_once BASE_PATH . '/shared/accounts-input-vat.php';
require_once BASE_PATH . '/shared/notifications.php';

function amendments_require_access(): void
{
    require_login();
    if (!accounts_can('amendments.view')) {
        http_response_code(403);
        exit('You do not have access to Accounting Amendments.');
    }
}

function amendments_schema_ready(): void
{
    static $ready = false;
    if ($ready) return;
    $queries = [
        "CREATE TABLE IF NOT EXISTS accounting_amendments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, subject VARCHAR(190) NOT NULL, application_key VARCHAR(40) NOT NULL, period_key VARCHAR(20) NULL, related_reference VARCHAR(190) NULL, related_url VARCHAR(255) NULL, priority VARCHAR(20) NOT NULL DEFAULT 'normal', status VARCHAR(40) NOT NULL DEFAULT 'open', created_by INT NOT NULL, created_by_name VARCHAR(190) NOT NULL, created_by_role VARCHAR(60) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, resolved_by INT NULL, resolved_by_name VARCHAR(190) NULL, resolved_at DATETIME NULL, KEY idx_accounting_amendments_status(status,updated_at), KEY idx_accounting_amendments_creator(created_by,updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounting_amendment_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, amendment_id BIGINT UNSIGNED NOT NULL, sender_user_id INT NOT NULL, sender_name VARCHAR(190) NOT NULL, sender_role VARCHAR(60) NOT NULL, message TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, edited_at DATETIME NULL, KEY idx_accounting_amendment_messages(amendment_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounting_amendment_attachments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, message_id BIGINT UNSIGNED NOT NULL, amendment_id BIGINT UNSIGNED NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(190) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_size BIGINT UNSIGNED NOT NULL, uploaded_by INT NOT NULL, uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, deleted_by INT NULL, UNIQUE KEY uq_accounting_amendment_file(stored_filename), KEY idx_accounting_amendment_attachment(amendment_id,message_id,deleted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounting_amendment_status_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, amendment_id BIGINT UNSIGNED NOT NULL, old_status VARCHAR(40) NULL, new_status VARCHAR(40) NOT NULL, changed_by INT NOT NULL, changed_by_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_accounting_amendment_status_history(amendment_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounting_amendment_reads (amendment_id BIGINT UNSIGNED NOT NULL, user_id INT NOT NULL, last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0, read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(amendment_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($queries as $query) db()->exec($query);
    $ready = true;
}

function amendments_csrf(): string
{
    if (empty($_SESSION['accounting_amendments_csrf'])) $_SESSION['accounting_amendments_csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['accounting_amendments_csrf'];
}

function amendments_verify_csrf(string $token): void
{
    if ($token === '' || !hash_equals(amendments_csrf(), $token)) throw new RuntimeException('Your session expired. Refresh and try again.');
}

function amendments_scope_sql(string $alias = 'a'): array
{
    if (accounts_is_owner()) return ['1=1', []];
    return ['(' . $alias . '.created_by=? OR ' . $alias . ".created_by_role='owner_admin')", [(int) (current_user()['id'] ?? 0)]];
}

function amendments_row(int $id): ?array
{
    [$scope, $params] = amendments_scope_sql('a');
    $stmt = db()->prepare("SELECT a.* FROM accounting_amendments a WHERE a.id=? AND {$scope} LIMIT 1");
    $stmt->execute(array_merge([$id], $params));
    return $stmt->fetch() ?: null;
}

function amendments_allowed_applications(): array
{
    return ['input_vat'=>'Input VAT','output_vat'=>'Output VAT','import_vat'=>'Import VAT','vat_reconciliation'=>'VAT Reconciliation','general_accounts'=>'General Accounts'];
}

function amendments_notify(array $amendment, int $messageId, string $summary): void
{
    $user = current_user();
    $recipients = accounts_is_owner()
        ? array_merge([(int) ($amendment['created_by'] ?? 0)], notifications_role_recipients(['accountant']))
        : notifications_role_recipients(['owner_admin']);
    $recipients = array_values(array_filter(array_unique(array_map('intval', $recipients)), static fn(int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0)));
    if (!$recipients) return;
    notifications_create([
        'title' => 'Accounting amendment update',
        'message' => ($amendment['subject'] ?? 'Accounting amendment') . ' — ' . mb_substr($summary, 0, 120),
        'module' => 'accounting_amendments',
        'related_type' => 'accounting_amendment',
        'related_id' => (int) $amendment['id'],
        'priority' => (string) ($amendment['priority'] ?? 'normal'),
        'deduplication_key' => 'accounting-amendment-message:' . $messageId,
        'required_delivery' => true,
        'action_link' => BASE_URL . '/apps/accounts/amendments.php?id=' . (int) $amendment['id'],
    ], $recipients);
}

function amendments_attachment_payload(array $row): array
{
    return ['id'=>(int)$row['id'],'name'=>(string)$row['original_filename'],'mime'=>(string)$row['mime_type'],'size'=>(int)$row['file_size'],'url'=>'amendment-file.php?id='.(int)$row['id']];
}

function amendments_thread_payload(array $amendment, bool $markRead = true): array
{
    $stmt = db()->prepare('SELECT * FROM accounting_amendment_messages WHERE amendment_id=? ORDER BY id');
    $stmt->execute([(int) $amendment['id']]);
    $messages = $stmt->fetchAll();
    $attachments = [];
    $fileStmt = db()->prepare('SELECT * FROM accounting_amendment_attachments WHERE amendment_id=? AND deleted_at IS NULL ORDER BY id');
    $fileStmt->execute([(int) $amendment['id']]);
    foreach ($fileStmt->fetchAll() as $file) $attachments[(int) $file['message_id']][] = amendments_attachment_payload($file);
    foreach ($messages as &$message) {
        $message['id'] = (int) $message['id'];
        $message['is_mine'] = (int) $message['sender_user_id'] === (int) (current_user()['id'] ?? 0);
        $message['attachments'] = $attachments[(int) $message['id']] ?? [];
    }
    unset($message);
    if ($markRead && $messages) {
        $last = (int) end($messages)['id'];
        db()->prepare('INSERT INTO accounting_amendment_reads(amendment_id,user_id,last_read_message_id)VALUES(?,?,?) ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id))')->execute([(int)$amendment['id'],(int)(current_user()['id']??0),$last]);
    }
    return ['amendment'=>$amendment,'messages'=>$messages];
}

function amendments_upload_files(int $amendmentId, int $messageId): array
{
    if (empty($_FILES['files']['name'])) return [];
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf','text/csv'=>'csv','application/csv'=>'csv','application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx'];
    $names = (array) $_FILES['files']['name'];
    if (count($names) > 8) throw new RuntimeException('Upload no more than 8 files at once.');
    $dir = BASE_PATH . '/uploads/accounting-amendments';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Protected attachment storage is unavailable.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $saved = [];
    foreach ($names as $index => $name) {
        $error = (int) ($_FILES['files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('An attachment could not be uploaded.');
        $size = (int) ($_FILES['files']['size'][$index] ?? 0);
        if ($size <= 0 || $size > 20 * 1024 * 1024) throw new RuntimeException('Each attachment must be 20 MB or smaller.');
        $tmp = (string) ($_FILES['files']['tmp_name'][$index] ?? '');
        $mime = (string) $finfo->file($tmp);
        if (!isset($allowed[$mime])) throw new RuntimeException('Only PDF, JPG, PNG, CSV, XLS and XLSX attachments are allowed.');
        $stored = bin2hex(random_bytes(18)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) throw new RuntimeException('The attachment could not be saved.');
        db()->prepare('INSERT INTO accounting_amendment_attachments(message_id,amendment_id,original_filename,stored_filename,mime_type,file_size,uploaded_by)VALUES(?,?,?,?,?,?,?)')->execute([$messageId,$amendmentId,basename((string)$name),$stored,$mime,$size,(int)(current_user()['id']??0)]);
        $saved[] = $stored;
    }
    return $saved;
}

function amendments_unread_count(): int
{
    amendments_schema_ready();
    [$scope, $params] = amendments_scope_sql('a');
    $sql = "SELECT COUNT(*) FROM accounting_amendment_messages m JOIN accounting_amendments a ON a.id=m.amendment_id LEFT JOIN accounting_amendment_reads r ON r.amendment_id=a.id AND r.user_id=? WHERE {$scope} AND m.sender_user_id<>? AND m.id>COALESCE(r.last_read_message_id,0)";
    $userId = (int) (current_user()['id'] ?? 0);
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([$userId], $params, [$userId]));
    return (int) $stmt->fetchColumn();
}
