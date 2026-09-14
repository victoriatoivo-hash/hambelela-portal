<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/employment-letter.php';

requireLogin();
$user = currentUser();
$db = db();
ensureEmploymentLetterTable($db);

$id = (int)($_GET['id'] ?? 0);
$preview = isset($_GET['preview']);
if (!$id) {
    http_response_code(404);
    exit('Letter not found.');
}

$stmt = $db->prepare("SELECT el.*, e.emp_number, e.first_name, e.last_name, e.id_number, e.job_title, e.department, e.employment_type, e.start_date, CONCAT(e.first_name,' ',e.last_name) AS emp_name FROM employment_letters el JOIN employees e ON e.id=el.employee_id WHERE el.id=? LIMIT 1");
$stmt->execute([$id]);
$letter = $stmt->fetch();

if (!$letter) {
    http_response_code(404);
    exit('Letter not found.');
}

$isAdmin = ($user['role'] ?? '') === 'admin';
$isOwner = (($user['role'] ?? '') === 'employee') && ((int)($user['emp_id'] ?? 0) === (int)$letter['employee_id']);

if (!$isAdmin && !$isOwner) {
    http_response_code(403);
    exit('You are not allowed to access this letter.');
}

if (!$isAdmin && $letter['status'] !== 'published') {
    http_response_code(403);
    exit('This letter has not been published yet.');
}

if (!$isAdmin && (int)$letter['download_count'] >= (int)$letter['download_limit']) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Download limit reached</title><style>body{font-family:Arial,sans-serif;background:#f4f6f2;padding:40px;color:#1f2933}.box{max-width:520px;margin:0 auto;background:#fff;border:1px solid #d9e2d0;border-radius:10px;padding:28px}a{color:#2d6a4f;font-weight:700}</style></head><body><div class="box"><h2>Download limit reached</h2><p>This employment confirmation letter has already been downloaded the allowed two times. Please contact HR if you need another copy.</p><p><a href="my-documents.php?tab=letters">Back to My Documents</a></p></div></body></html>';
    exit;
}

if (!$isAdmin) {
    $db->prepare("UPDATE employment_letters SET download_count=download_count+1 WHERE id=?")->execute([$id]);
    $letter['download_count'] = (int)$letter['download_count'] + 1;
}

$filename = 'employment-confirmation-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$letter['emp_number']) . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$letter['letter_no']) . ($preview ? '.html' : '.pdf');

if (!$preview) {
    $pdf = renderEmploymentLetterPdf($db, $letter);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} else {
    $html = renderEmploymentLetterHtml($db, $letter);
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    echo $html;
}
