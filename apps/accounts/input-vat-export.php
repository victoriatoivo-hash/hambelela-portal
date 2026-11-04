<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/employee-features.php';
require_once BASE_PATH.'/shared/accounts-input-vat.php';
require_once BASE_PATH.'/shared/input-vat-month-export.php';
accounts_require_input_vat_access();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$file=null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
    $month=(string)($_GET['month'] ?? '');
    iv_export_period($month);
    $file=iv_export_xlsx($month,iv_export_rows(db(),$month));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Hambelela-Input-VAT-'.$month.'.xlsx"');
    header('Content-Length: '.filesize($file));
    readfile($file);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 400 : 500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>$error instanceof InvalidArgumentException ? $error->getMessage() : 'Excel export could not be prepared. Please retry or contact the portal administrator.']);
    if (!$error instanceof InvalidArgumentException) error_log('Input VAT Excel export: '.$error->getMessage());
} finally {
    if ($file !== null && is_file($file)) unlink($file);
}
