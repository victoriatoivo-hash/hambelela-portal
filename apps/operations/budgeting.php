<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_role('owner_admin');
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: budget-planning.php'.($query !== '' ? '?'.$query : ''), true, 302);
exit;
