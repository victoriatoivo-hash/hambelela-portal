<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_login();

$attachmentId = (int) ($_GET['id'] ?? 0);
$itemId = (int) ($_GET['item_id'] ?? 0);
$mode = (string) ($_GET['mode'] ?? 'view');
if ($attachmentId <= 0 || $itemId <= 0 || !ops_table_exists('ops_packing_attachments')) { http_response_code(404); exit('File not found.'); }
$row = ops_rows("SELECT a.*, t.archived_at AS item_archived_at, t.deleted_at AS item_deleted_at FROM ops_packing_attachments a JOIN ops_packing_tasks t ON t.id=a.packing_item_id WHERE a.id=? AND a.packing_item_id=? AND a.deleted_at IS NULL LIMIT 1", [$attachmentId, $itemId])[0] ?? null;
if (!$row || !empty($row['item_archived_at']) || !empty($row['item_deleted_at'])) { http_response_code(404); exit('File not found.'); }
$stored = basename((string) $row['stored_filename']);
$path = BASE_PATH . '/uploads/packing-item-attachments/' . $stored;
if ($stored === '' || !is_file($path)) { http_response_code(404); exit('File not found.'); }
$filename = str_replace(["\r", "\n", '"'], '', (string) $row['original_filename']);
$mime = (string) $row['mime_type'];
$inline = in_array($mime, ['application/pdf','image/jpeg','image/png','image/webp'], true) && $mode !== 'download';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($path);
