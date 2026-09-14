<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/marketing.php';
marketing_require_access();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST required.');
    }
    marketing_verify();
    $destination = trim((string)($_POST['destination'] ?? ''));
    $generated = trim((string)($_POST['generated_url'] ?? ''));
    foreach ([$destination, $generated] as $url) {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true)) {
            throw new RuntimeException('Enter a valid http or https destination URL.');
        }
    }
    if (strlen($generated) > 1500 || strlen($destination) > 1000) {
        throw new RuntimeException('The tracked URL is too long.');
    }
    $user = current_user();
    db()->prepare('INSERT INTO marketing_tracked_links(destination_url,source_label,medium_label,campaign_label,content_label,generated_url,created_by,created_by_name)VALUES(?,?,?,?,?,?,?,?)')->execute([
        $destination,
        trim((string)($_POST['source'] ?? '')) ?: null,
        trim((string)($_POST['medium'] ?? '')) ?: null,
        trim((string)($_POST['campaign'] ?? '')) ?: null,
        trim((string)($_POST['content'] ?? '')) ?: null,
        $generated,
        (int)$user['id'],
        (string)$user['name'],
    ]);
    $id = (int)db()->lastInsertId();
    marketing_phase3_audit('tracked_link_generated', 'tracked_link', $id, $generated);
    echo json_encode(['ok'=>true,'id'=>$id], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>$error->getMessage()]);
}
