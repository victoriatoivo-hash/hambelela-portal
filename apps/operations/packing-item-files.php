<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function packing_files_reply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function packing_files_schema_ready(): bool
{
    if (ops_table_exists('ops_packing_attachments')) return true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS ops_packing_attachments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            packing_item_id INT NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(190) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL,
            uploaded_by INT NULL,
            uploaded_by_name VARCHAR(190) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            deleted_by INT NULL,
            UNIQUE KEY uniq_packing_stored_filename (stored_filename),
            INDEX idx_packing_attachment_item (packing_item_id, deleted_at, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Throwable $error) {
        error_log('Packing attachment schema failed: ' . $error->getMessage());
        return false;
    }
}

function packing_files_item(int $itemId): ?array
{
    if ($itemId <= 0) return null;
    $where = ['id = ?'];
    if (ops_column_exists('ops_packing_tasks', 'archived_at')) $where[] = 'archived_at IS NULL';
    if (ops_column_exists('ops_packing_tasks', 'deleted_at')) $where[] = 'deleted_at IS NULL';
    return ops_rows('SELECT id, assigned_employee_id FROM ops_packing_tasks WHERE ' . implode(' AND ', $where) . ' LIMIT 1', [$itemId])[0] ?? null;
}

function packing_files_can_access(array $item, bool $write = false): bool
{
    if (!$write) return true;
    return user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager')
        || (int) ($item['assigned_employee_id'] ?? 0) === (int) ops_current_employee_id();
}

function packing_files_payload(array $row): array
{
    $id = (int) $row['id'];
    $itemId = (int) $row['packing_item_id'];
    $employeeId = (int) ops_current_employee_id();
    return [
        'id' => $id,
        'item_id' => $itemId,
        'name' => (string) $row['original_filename'],
        'size' => (int) $row['file_size'],
        'mime_type' => (string) $row['mime_type'],
        'uploaded_by' => (string) $row['uploaded_by_name'],
        'uploaded_at' => (string) $row['created_at'],
        'view_url' => 'packing-item-file.php?id=' . $id . '&item_id=' . $itemId . '&mode=view',
        'download_url' => 'packing-item-file.php?id=' . $id . '&item_id=' . $itemId . '&mode=download',
        'can_delete' => user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager') || (int) ($row['uploaded_by'] ?? 0) === $employeeId,
    ];
}

try {
    if (!packing_files_schema_ready()) throw new RuntimeException('File storage is not available.');
    $itemId = (int) ($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
    $item = packing_files_item($itemId);
    if (!$item || !packing_files_can_access($item)) packing_files_reply(['success' => false, 'message' => 'Packing item not found.'], 404);
    $action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'list');

    if ($action === 'list') {
        $rows = ops_rows('SELECT * FROM ops_packing_attachments WHERE packing_item_id = ? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC', [$itemId]);
        packing_files_reply(['success' => true, 'attachments' => array_map('packing_files_payload', $rows)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') packing_files_reply(['success' => false, 'message' => 'Invalid request method.'], 405);
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals((string) ($_SESSION['packing_attachment_csrf'] ?? ''), $csrf)) packing_files_reply(['success' => false, 'message' => 'Your session token expired. Refresh and try again.'], 403);

    if ($action === 'delete') {
        $attachmentId = (int) ($_POST['attachment_id'] ?? 0);
        $row = ops_rows('SELECT * FROM ops_packing_attachments WHERE id = ? AND packing_item_id = ? AND deleted_at IS NULL LIMIT 1', [$attachmentId, $itemId])[0] ?? null;
        if (!$row) packing_files_reply(['success' => false, 'message' => 'Attachment not found.'], 404);
        $allowed = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager') || (int) ($row['uploaded_by'] ?? 0) === (int) ops_current_employee_id();
        if (!$allowed) packing_files_reply(['success' => false, 'message' => 'You cannot delete this attachment.'], 403);
        db()->prepare('UPDATE ops_packing_attachments SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND packing_item_id = ? AND deleted_at IS NULL')->execute([ops_current_employee_id() ?: null, $attachmentId, $itemId]);
        ops_activity_log('packing_attachment_deleted', 'packing_task', $itemId, ['attachment_id' => $attachmentId, 'filename' => $row['original_filename']]);
        packing_files_reply(['success' => true, 'attachment_id' => $attachmentId]);
    }

    if ($action !== 'upload' || !packing_files_can_access($item, true)) packing_files_reply(['success' => false, 'message' => 'You cannot upload files to this packing item.'], 403);
    if (isset($_FILES['file'])) {
        $names = [(string) ($_FILES['file']['name'] ?? '')];
        $tmpNames = [(string) ($_FILES['file']['tmp_name'] ?? '')];
        $errors = [(int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE)];
        $sizes = [(int) ($_FILES['file']['size'] ?? 0)];
    } else {
        $names = (array) ($_FILES['files']['name'] ?? []);
        $tmpNames = (array) ($_FILES['files']['tmp_name'] ?? []);
        $errors = (array) ($_FILES['files']['error'] ?? []);
        $sizes = (array) ($_FILES['files']['size'] ?? []);
    }
    if (!$names || count($names) > 10) packing_files_reply(['success' => false, 'message' => 'Select between 1 and 10 files.'], 422);

    $allowedMimes = ['application/pdf'=>'pdf', 'image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
    $uploadDir = BASE_PATH . '/uploads/packing-item-attachments';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) throw new RuntimeException('Unable to create protected file storage.');
    $uploaded = []; $failed = [];
    foreach ($names as $index => $name) {
        $original = trim((string) basename(str_replace('\\', '/', (string) $name)));
        try {
            if ((int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($tmpNames[$index] ?? ''))) throw new RuntimeException('The upload did not complete.');
            $size = (int) ($sizes[$index] ?? 0);
            if ($size <= 0 || $size > 10 * 1024 * 1024) throw new RuntimeException('The file must be no larger than 10 MB.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $tmpNames[$index]) ?: '';
            if (!isset($allowedMimes[$mime])) throw new RuntimeException('Only PDF, JPG, PNG and WebP files are allowed.');
            $stored = bin2hex(random_bytes(24)) . '.' . $allowedMimes[$mime];
            $target = $uploadDir . DIRECTORY_SEPARATOR . $stored;
            if (!move_uploaded_file((string) $tmpNames[$index], $target)) throw new RuntimeException('The file could not be stored.');
            try {
                $stmt = db()->prepare('INSERT INTO ops_packing_attachments (packing_item_id, original_filename, stored_filename, mime_type, file_size, uploaded_by, uploaded_by_name) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$itemId, mb_substr($original ?: ('attachment.' . $allowedMimes[$mime]), 0, 255), $stored, $mime, $size, ops_current_employee_id() ?: null, (string) (current_user()['name'] ?? 'Portal user')]);
            } catch (Throwable $error) {
                @unlink($target);
                throw $error;
            }
            $attachmentId = (int) db()->lastInsertId();
            $row = ops_rows('SELECT * FROM ops_packing_attachments WHERE id = ? AND packing_item_id = ? LIMIT 1', [$attachmentId, $itemId])[0];
            $uploaded[] = packing_files_payload($row);
            ops_activity_log('packing_attachment_uploaded', 'packing_task', $itemId, ['attachment_id' => $attachmentId, 'filename' => $original, 'size' => $size, 'mime_type' => $mime]);
            packing_create_update_notifications($itemId, 'file_uploaded', $attachmentId, (int) (ops_current_employee_id() ?: 0));
        } catch (Throwable $error) {
            $failed[] = ['name' => $original ?: 'Unnamed file', 'message' => $error->getMessage()];
        }
    }
    packing_files_reply(['success' => (bool) $uploaded, 'uploaded' => $uploaded, 'failed' => $failed], $uploaded ? 200 : 422);
} catch (Throwable $error) {
    error_log('Packing attachment request failed: ' . $error->getMessage());
    packing_files_reply(['success' => false, 'message' => $error->getMessage()], 500);
}
