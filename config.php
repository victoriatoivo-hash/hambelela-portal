<?php

declare(strict_types=1);

define('APP_NAME', 'Hambelela Business Portal');
define('BASE_PATH', __DIR__);
define('BASE_URL', '');

$localConfig = __DIR__ . '/config.local.php';
$localSecrets = [];

if (is_file($localConfig)) {
    $loadedLocalConfig = require $localConfig;
    if (is_array($loadedLocalConfig)) {
        $localSecrets = $loadedLocalConfig;
    }
}

define('DB_HOST', getenv('HAMBELELA_DB_HOST') ?: ($localSecrets['db_host'] ?? 'localhost'));
define('DB_NAME', getenv('HAMBELELA_DB_NAME') ?: ($localSecrets['db_name'] ?? 'hambelela_portal'));
define('DB_USER', getenv('HAMBELELA_DB_USER') ?: ($localSecrets['db_user'] ?? 'root'));
define('DB_PASS', getenv('HAMBELELA_DB_PASS') ?: ($localSecrets['db_pass'] ?? ''));
define('DB_CHARSET', getenv('HAMBELELA_DB_CHARSET') ?: ($localSecrets['db_charset'] ?? 'utf8mb4'));
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: ($localSecrets['openai_api_key'] ?? ''));
define('OPENAI_MODEL', getenv('OPENAI_MODEL') ?: ($localSecrets['openai_model'] ?? 'gpt-4o'));
define('WC_STORE_URL', rtrim(getenv('WC_STORE_URL') ?: ($localSecrets['wc_store_url'] ?? ''), '/'));
define('WC_CONSUMER_KEY', getenv('WC_CONSUMER_KEY') ?: ($localSecrets['wc_consumer_key'] ?? ''));
define('WC_CONSUMER_SECRET', getenv('WC_CONSUMER_SECRET') ?: ($localSecrets['wc_consumer_secret'] ?? ''));
