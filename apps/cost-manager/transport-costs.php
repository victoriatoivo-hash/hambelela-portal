<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_role('owner_admin');
$cwPageKey = 'transport-costs';
require __DIR__.'/workbook-page.php';
