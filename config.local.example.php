<?php

declare(strict_types=1);

return [
    'db_host' => 'localhost',
    'db_name' => 'your_cpanel_database_name',
    'db_user' => 'your_cpanel_database_user',
    'db_pass' => 'your_database_password',
    'db_charset' => 'utf8mb4',

    'openai_api_key' => 'paste-your-new-openai-api-key-here',
    'openai_model' => 'gpt-4o',
    'system_issues_openai_model' => 'gpt-5.6-sol',

    'wc_store_url' => 'https://www.hambelelaorganic.com',
    'wc_consumer_key' => 'paste-new-read-only-consumer-key-here',
    'wc_consumer_secret' => 'paste-new-read-only-consumer-secret-here',

    'monday_api_token' => 'paste-your-monday-api-token-here',

    // Embedded HR Portal database.
    // By default, apps/hr-portal reads the live HR config from:
    // /home/hambele1/hr.hambelelaorganic.com/config.php
    // Set these only if you want the Business Portal test copy to use a separate HR database.
    'hr_live_config_path' => '/home/hambele1/hr.hambelelaorganic.com/config.php',
    'hr_db_host' => '',
    'hr_db_name' => '',
    'hr_db_user' => '',
    'hr_db_pass' => '',
];
