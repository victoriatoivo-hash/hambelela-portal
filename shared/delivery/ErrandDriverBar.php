<?php if(!defined('BASE_PATH')||empty($driverPage)||!empty($driverOversight))return;
$_SESSION['delivery_errand_csrf']??=bin2hex(random_bytes(32));?>
<link rel="stylesheet" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/css/driver-errands.css?v=2">
<a class="secondary" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/apps/delivery/index.php?errands=1">My errands <span class="errand-count" data-errand-count hidden></span></a>
<script defer src="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/js/driver-errands.js?v=2" data-errand-notices data-driver="1" data-url="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/apps/delivery/index.php?errands=notice" data-csrf="<?=htmlspecialchars($_SESSION['delivery_errand_csrf'],ENT_QUOTES)?>"></script>
