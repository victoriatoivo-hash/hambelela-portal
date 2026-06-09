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

    'wc_store_url' => 'https://www.hambelelaorganic.com',
    'wc_consumer_key' => 'paste-new-read-only-consumer-key-here',
    'wc_consumer_secret' => 'paste-new-read-only-consumer-secret-here',

    'monday_api_token' => 'paste-your-monday-api-token-here',
    'monday_packing_board_id' => '1590283675',

    // Duplicate/test HR Portal database used by apps/hr-portal.
    // Keep this separate from the live hr.hambelelaorganic.com database until final approval.
    'hr_db_host' => 'localhost',
    'hr_db_name' => 'hambele1_hambelela_hr_test',
    'hr_db_user' => 'your_hr_test_database_user',
    'hr_db_pass' => 'your_hr_test_database_password',
];
