<?php
declare(strict_types=1);

function hr_certificate_filename(string $value): ?string
{
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,179}\.(?:pdf|jpe?g|png)\z/iD', $value)
        || strpos($value, '..') !== false) { return null; }
    return $value;
}

function hr_certificate_reference_filename(string $reference): ?string
{
    $reference = trim($reference);
    $prefix = 'uploads/certificates/';
    if (strncmp($reference, $prefix, strlen($prefix)) === 0) {
        $reference = substr($reference, strlen($prefix));
    }
    return hr_certificate_filename($reference);
}

function hr_certificate_file(string $filename): ?array
{
    if (hr_certificate_filename($filename) === null) { return null; }
    $root = realpath(dirname(__DIR__) . '/uploads/certificates');
    if ($root === false) { return null; }
    $candidate = $root . DIRECTORY_SEPARATOR . $filename;
    if (is_link($candidate)) { return null; }
    $path = realpath($candidate);
    if ($path === false || dirname($path) !== $root || !is_file($path) || !is_readable($path)) { return null; }
    $types = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];
    $mime = $types[strtolower(pathinfo($filename, PATHINFO_EXTENSION))];
    if (!class_exists('finfo')) { return null; }
    $detector = new finfo(FILEINFO_MIME_TYPE);
    if (@$detector->file($path) !== $mime) { return null; }
    return ['filename'=>$filename,'path'=>$path,'mime'=>$mime];
}

function hr_leave_document_state(?string $reference): array
{
    if (trim((string) $reference) === '') { return ['state'=>'none']; }
    $filename = hr_certificate_reference_filename((string) $reference);
    if ($filename === null || hr_certificate_file($filename) === null) { return ['state'=>'unavailable']; }
    return ['state'=>'available','filename'=>$filename];
}

function hr_leave_document_markup(?string $reference, bool $backCaptured = false): string
{
    $document = hr_leave_document_state($reference);
    if ($document['state'] === 'none') { return '<span class="leave-document-empty">' . ($backCaptured ? '—' : 'Not uploaded') . '</span>'; }
    if ($document['state'] !== 'available') {
        return '<span class="leave-document-empty is-unavailable"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5m0 4h.01M10.3 3.8L2.1 18a2 2 0 001.7 3h16.4a2 2 0 001.7-3L13.7 3.8a2 2 0 00-3.4 0z"/></svg>File unavailable</span>';
    }
    $href = 'download-certificate.php?file=' . rawurlencode($document['filename']);
    return '<a class="leave-document-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
        . '" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6M8 13h8M8 17h6"/></svg>View document</a>';
}

/** Database ownership is authoritative; filenames do not grant access. */
function hr_certificate_authorized(PDO $db, array $sessionUser, string $filename, bool $adminOnly): bool
{
    if (hr_certificate_filename($filename) === null) { return false; }
    $id = (int) ($sessionUser['id'] ?? 0);
    if ($id < 1) { return false; }
    $stmt = $db->prepare('SELECT id,role,employee_id,active FROM users WHERE id=?');
    $stmt->execute([$id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$account || (int) $account['active'] !== 1 || $account['role'] !== ($sessionUser['role'] ?? '')
        || !in_array($account['role'], ['admin','employee'], true)) { return false; }
    $isAdmin = $account['role'] === 'admin';
    if ($adminOnly && !$isAdmin) { return false; }
    $employeeId = (int) $account['employee_id'];
    if (!$isAdmin && ($employeeId < 1 || $employeeId !== (int) ($sessionUser['emp_id'] ?? 0))) { return false; }
    $sql = 'SELECT id FROM leave_requests WHERE TRIM(certificate) IN (?,?)';
    $params = [$filename,'uploads/certificates/' . $filename];
    if (!$isAdmin) { $sql .= ' AND employee_id=?'; $params[] = $employeeId; }
    $stmt = $db->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $allowed = (bool) $stmt->fetchColumn();
    $stmt->closeCursor();
    return $allowed;
}

function hr_certificate_respond(bool $adminOnly): void
{
    requireLogin();
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $user = currentUser() ?? [];
    if ($adminOnly && ($user['role'] ?? '') !== 'admin') {
        http_response_code(403); exit('Access denied.');
    }
    $value = $_GET['file'] ?? '';
    $filename = is_string($value) ? hr_certificate_filename($value) : null;
    if ($filename === null) { http_response_code(400); exit('Invalid document request.'); }
    try {
        if (!hr_certificate_authorized(db(), $user, $filename, $adminOnly)) {
            http_response_code(403); exit('Access denied.');
        }
        $file = hr_certificate_file($filename);
        $handle = $file ? @fopen($file['path'], 'rb') : false;
        if (!$file || !$handle) { http_response_code(404); exit('Document unavailable.'); }
        $stat = fstat($handle);
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . (int) $stat['size']);
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: sandbox; default-src 'none'");
        fpassthru($handle);
        fclose($handle);
        exit;
    } catch (Throwable $error) {
        error_log('HR document access failed: ' . $error->getMessage());
        http_response_code(503); exit('Document temporarily unavailable.');
    }
}
