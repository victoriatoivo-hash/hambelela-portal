<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_login();
$pageTitle = 'Inventory | ' . APP_NAME;
$activeApp = 'dashboard';
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module"><section class="module-header"><div><p class="eyebrow">Soon</p><h1>Inventory</h1><p>Stock levels and reorder alerts will live here.</p></div></section></main>
<?php include BASE_PATH . '/shared/footer.php'; ?>

