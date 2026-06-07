<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_login();
$pageTitle = 'HR Portal | ' . APP_NAME;
$activeApp = 'dashboard';
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module"><section class="module-header"><div><p class="eyebrow">Existing app</p><h1>HR Portal</h1><p>Connect the existing HR portal files here.</p></div></section></main>
<?php include BASE_PATH . '/shared/footer.php'; ?>

