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
    'monday_packing_board_id' => 'paste-packing-board-id-here',
    'monday_packing_group_id' => 'topics',
    'monday_packing_columns' => [
        'received_weight' => 'received_weight',
        'priority' => 'priority',
        'date_loaded' => 'date_loaded',
        'quantity_to_pack' => 'quantity_to_pack',
        'person_responsible' => 'person',
        'quantity_packed' => 'quantity_packed',
        'date_completed' => 'date_completed',
        'website_quantity_updated' => 'website_quantity_updated',
        'packing_website_confirmed' => 'packing_website_confirmed',
        'packing_status' => 'packing_status',
        'notes' => 'notes',
    ],

    // Protected HR app inside the Business Portal.
    // Use either hr_portal_pin for a plain PIN or hr_portal_pin_hash for a password_hash() value.
    'hr_portal_pin' => 'change-this-pin',
    'hr_portal_pin_hash' => '',
    'hr_portal_unlock_secret' => 'change-this-long-random-secret',

    // Use a separate test HR database until you are ready to switch the Business Portal HR app live.
    'hr_db_host' => 'localhost',
    'hr_db_name' => 'hambele1_hambelela_hr_test',
    'hr_db_user' => 'your_hr_test_database_user',
    'hr_db_pass' => 'your_hr_test_database_password',
];
