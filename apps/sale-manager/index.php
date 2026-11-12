<?php
// Keep bookmarks usable without forwarding a stale portal write to WordPress.
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || isset($_GET['action'])) {
    http_response_code(410);
    header('Content-Type: application/json');
    echo json_encode(['error'=>'Sale Manager has moved. Open WooCommerce → Sale Manager to continue.']);
    exit;
}
header('Location: https://hambelelaorganic.com/wp-admin/admin.php?page=hambelela-sale-manager', true, 302);
exit;
