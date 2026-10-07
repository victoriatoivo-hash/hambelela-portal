<?php
declare(strict_types=1);
// Separate non-secret release switches. Never rewrite the portal's config.local.php.
if(!defined('BASE_PATH'))exit;
$deliveryFlagsFile=BASE_PATH.'/storage/delivery-release-flags.json';
$deliveryFlags=is_file($deliveryFlagsFile)?json_decode((string)file_get_contents($deliveryFlagsFile),true):[];
if(!defined('DELIVERY_ENABLED'))define('DELIVERY_ENABLED',($deliveryFlags['delivery_enabled']??false)===true);
if(!defined('DELIVERY_SETUP_ENABLED'))define('DELIVERY_SETUP_ENABLED',($deliveryFlags['setup_enabled']??false)===true);
unset($deliveryFlags,$deliveryFlagsFile);
