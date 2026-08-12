<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/cost-workbook-history.php';

require_role('owner_admin');
cw_history_require_read_only_request();
cw_history_redirect();
