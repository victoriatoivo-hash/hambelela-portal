<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/config.php';
header('Cache-Control: no-store');
// Existing app bookmarks now use the normal employee login.
header('Location: '.BASE_URL.'/login.php?return='.rawurlencode(BASE_URL.'/apps/delivery/driver/index.php'),true,303);
exit;
