<?php if(!defined('BASE_PATH')||empty($driverPage)||!empty($driverOversight))return;
$_SESSION['delivery_errand_csrf']??=bin2hex(random_bytes(32));?>
<a class="secondary" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/apps/delivery/index.php?errands=1">My errands</a>
<script defer src="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/js/driver-errands.js?v=1" data-errand-notices data-url="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/apps/delivery/index.php?errands=notice" data-csrf="<?=htmlspecialchars($_SESSION['delivery_errand_csrf'],ENT_QUOTES)?>"></script>
