<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

function wa_statuses(): array
{
    return [
        'awaiting_response' => 'Awaiting Response',
        'follow_up' => 'Follow-up Required',
        'waiting_customer' => 'Waiting for Customer',
        'waiting_stock' => 'Waiting for Stock',
        'pending_payment' => 'Pending Payment',
        'pending_courier' => 'Pending Courier',
        'resolved' => 'Resolved',
        'escalated' => 'Escalated',
        'abandoned' => 'Abandoned',
    ];
}

function wa_sources(): array
{
    return ['manual', 'whatsapp_business', 'meta_import', 'csv_import'];
}

function wa_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        return $value . ':00';
    }

    return $value;
}

function wa_money(float $amount): string
{
    return 'N$ ' . number_format($amount, 2);
}

function wa_minutes(?float $minutes): string
{
    if ($minutes === null) {
        return '-';
    }

    $minutes = max(0, (int) round($minutes));
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;

    return $hours > 0 ? $hours . 'h ' . $remaining . 'm' : $remaining . 'm';
}

function wa_date_filters(array $filters): array
{
    $today = new DateTimeImmutable('today');
    if ($filters['period'] === 'today') {
        return [$today->format('Y-m-d'), $today->format('Y-m-d')];
    }
    if ($filters['period'] === 'this_week') {
        return [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')];
    }
    if ($filters['period'] === 'this_month') {
        return [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')];
    }
    if ($filters['period'] === 'custom') {
        return [$filters['date_from'] ?: $today->modify('first day of this month')->format('Y-m-d'), $filters['date_to'] ?: $today->format('Y-m-d')];
    }

    return [$today->modify('-1 day')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')];
}

function wa_detect_flag(string $text): ?string
{
    $patterns = [
        'hello???',
        'bad service',
        'ignored',
        'still waiting',
        'unprofessional',
        'anyone responding',
        'no response',
        'complaint',
        'angry',
    ];
    $haystack = strtolower($text);
    foreach ($patterns as $pattern) {
        if (str_contains($haystack, $pattern)) {
            return 'Flagged phrase: ' . $pattern;
        }
    }

    return null;
}

function wa_column_exists(string $table, string $column): bool
{
    return ops_column_exists($table, $column);
}

function wa_add_column(string $table, string $column, string $definition): void
{
    if (!wa_column_exists($table, $column)) {
        db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function wa_ensure_tables(): bool
{
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NULL,
                is_secret TINYINT(1) NOT NULL DEFAULT 0,
                updated_by INT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (updated_by) REFERENCES ops_employees(id)
            )"
        );
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_contacts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                wa_id VARCHAR(80) NOT NULL UNIQUE,
                display_name VARCHAR(190),
                phone_number VARCHAR(80),
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            )"
        );
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_conversations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                contact_id INT NULL,
                customer_name VARCHAR(190) NOT NULL,
                customer_phone VARCHAR(80),
                source ENUM('manual', 'whatsapp_business', 'meta_import', 'csv_import') NOT NULL DEFAULT 'manual',
                status ENUM('awaiting_response', 'follow_up', 'waiting_customer', 'waiting_stock', 'pending_payment', 'pending_courier', 'resolved', 'escalated', 'abandoned') NOT NULL DEFAULT 'awaiting_response',
                assigned_employee_id INT NULL,
                first_customer_message_at DATETIME NULL,
                first_response_at DATETIME NULL,
                last_customer_message_at DATETIME NULL,
                last_staff_response_at DATETIME NULL,
                last_message_at DATETIME NULL,
                follow_up_at DATETIME NULL,
                order_id INT NULL,
                converted_to_sale TINYINT(1) NOT NULL DEFAULT 0,
                sale_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                complaint_flag TINYINT(1) NOT NULL DEFAULT 0,
                flagged_reason VARCHAR(255),
                faq_topic VARCHAR(190),
                phone_number_id VARCHAR(80),
                wa_business_account_id VARCHAR(80),
                meta_conversation_id VARCHAR(120),
                notes TEXT,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wa_status (status),
                INDEX idx_wa_follow_up (follow_up_at),
                INDEX idx_wa_created (created_at),
                INDEX idx_wa_customer_phone (customer_phone),
                FOREIGN KEY (contact_id) REFERENCES ops_whatsapp_contacts(id),
                FOREIGN KEY (assigned_employee_id) REFERENCES ops_employees(id),
                FOREIGN KEY (order_id) REFERENCES ops_orders(id),
                FOREIGN KEY (created_by) REFERENCES ops_employees(id)
            )"
        );
        foreach ([
            'contact_id' => 'INT NULL AFTER id',
            'last_message_at' => 'DATETIME NULL AFTER last_staff_response_at',
            'phone_number_id' => 'VARCHAR(80) NULL AFTER faq_topic',
            'wa_business_account_id' => 'VARCHAR(80) NULL AFTER phone_number_id',
            'meta_conversation_id' => 'VARCHAR(120) NULL AFTER wa_business_account_id',
        ] as $column => $definition) {
            wa_add_column('ops_whatsapp_conversations', $column, $definition);
        }
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                contact_id INT NULL,
                wa_message_id VARCHAR(120) NULL UNIQUE,
                direction ENUM('inbound', 'outbound') NOT NULL,
                message_type VARCHAR(40) NOT NULL DEFAULT 'text',
                message_text TEXT NULL,
                message_at DATETIME NOT NULL,
                status VARCHAR(40),
                status_at DATETIME NULL,
                employee_id INT NULL,
                raw_payload JSON NULL,
                external_message_id VARCHAR(120),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_wa_msg_at (message_at),
                INDEX idx_wa_msg_direction (direction),
                FOREIGN KEY (conversation_id) REFERENCES ops_whatsapp_conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (contact_id) REFERENCES ops_whatsapp_contacts(id),
                FOREIGN KEY (employee_id) REFERENCES ops_employees(id)
            )"
        );
        foreach ([
            'contact_id' => 'INT NULL AFTER conversation_id',
            'wa_message_id' => 'VARCHAR(120) NULL UNIQUE AFTER contact_id',
            'message_type' => "VARCHAR(40) NOT NULL DEFAULT 'text' AFTER direction",
            'status' => 'VARCHAR(40) NULL AFTER message_at',
            'status_at' => 'DATETIME NULL AFTER status',
            'raw_payload' => 'JSON NULL AFTER employee_id',
        ] as $column => $definition) {
            wa_add_column('ops_whatsapp_messages', $column, $definition);
        }
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_tags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tag_key VARCHAR(80) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                color VARCHAR(20) NOT NULL DEFAULT 'slate',
                active TINYINT(1) NOT NULL DEFAULT 1
            )"
        );
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_conversation_tags (
                conversation_id INT NOT NULL,
                tag_id INT NOT NULL,
                PRIMARY KEY (conversation_id, tag_id),
                FOREIGN KEY (conversation_id) REFERENCES ops_whatsapp_conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (tag_id) REFERENCES ops_whatsapp_tags(id) ON DELETE CASCADE
            )"
        );
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_followups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                due_at DATETIME NOT NULL,
                reason VARCHAR(255),
                status ENUM('pending', 'done', 'missed') NOT NULL DEFAULT 'pending',
                completed_at DATETIME NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES ops_whatsapp_conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES ops_employees(id)
            )"
        );
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_flagged_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                message_id INT NULL,
                flag_type VARCHAR(80) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                severity ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
                resolved_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES ops_whatsapp_conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (message_id) REFERENCES ops_whatsapp_messages(id) ON DELETE SET NULL
            )"
        );
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_webhook_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(80) NOT NULL,
                phone_number_id VARCHAR(80),
                wa_message_id VARCHAR(120),
                payload JSON NULL,
                processed_status ENUM('received', 'processed', 'failed') NOT NULL DEFAULT 'received',
                error_message TEXT NULL,
                received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $seed = db()->prepare(
            "INSERT INTO ops_whatsapp_tags (tag_key, name, color)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), color = VALUES(color), active = 1"
        );
        foreach ([
            ['sale_completed', 'Sale Completed', 'green'],
            ['follow_up_required', 'Follow-up Required', 'amber'],
            ['complaint', 'Complaint', 'red'],
            ['waiting_for_stock', 'Waiting for Stock', 'purple'],
            ['courier_inquiry', 'Courier Inquiry', 'blue'],
            ['customer_undecided', 'Customer Undecided', 'slate'],
            ['payment_pending', 'Payment Pending', 'orange'],
            ['escalated', 'Escalated', 'red'],
            ['resolved', 'Resolved', 'green'],
        ] as $tag) {
            $seed->execute($tag);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function wa_setting(string $key, string $default = ''): string
{
    if (!ops_table_exists('ops_whatsapp_settings')) {
        return $default;
    }

    $rows = ops_rows('SELECT setting_value FROM ops_whatsapp_settings WHERE setting_key = ? LIMIT 1', [$key]);

    return (string) ($rows[0]['setting_value'] ?? $default);
}

function wa_save_setting(string $key, string $value, bool $secret = false): void
{
    $stmt = db()->prepare(
        "INSERT INTO ops_whatsapp_settings (setting_key, setting_value, is_secret, updated_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([$key, $value, $secret ? 1 : 0, ops_current_employee_id()]);
}

function wa_mask_secret(string $value): string
{
    if ($value === '') {
        return 'Not set';
    }

    return substr($value, 0, 6) . str_repeat('*', max(6, strlen($value) - 10)) . substr($value, -4);
}

function wa_webhook_url(): string
{
    return BASE_URL . '/apps/operations/whatsapp-webhook.php';
}

function wa_log_webhook(string $type, array $payload, string $status = 'received', ?string $error = null, ?string $phoneNumberId = null, ?string $messageId = null): void
{
    if (!ops_table_exists('ops_whatsapp_webhook_logs')) {
        return;
    }

    $stmt = db()->prepare(
        "INSERT INTO ops_whatsapp_webhook_logs (event_type, phone_number_id, wa_message_id, payload, processed_status, error_message)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$type, $phoneNumberId, $messageId, json_encode($payload, JSON_UNESCAPED_SLASHES), $status, $error]);
}

function wa_upsert_contact(string $waId, string $name = '', string $phone = ''): int
{
    $stmt = db()->prepare(
        "INSERT INTO ops_whatsapp_contacts (wa_id, display_name, phone_number, last_seen_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE display_name = COALESCE(NULLIF(VALUES(display_name), ''), display_name), phone_number = COALESCE(NULLIF(VALUES(phone_number), ''), phone_number), last_seen_at = NOW()"
    );
    $stmt->execute([$waId, $name ?: null, $phone ?: $waId]);

    $rows = ops_rows('SELECT id FROM ops_whatsapp_contacts WHERE wa_id = ? LIMIT 1', [$waId]);

    return (int) ($rows[0]['id'] ?? 0);
}

function wa_find_or_create_conversation(int $contactId, string $customerName, string $phone, ?string $phoneNumberId, ?string $wabaId, ?string $messageAt): int
{
    $rows = ops_rows(
        "SELECT id FROM ops_whatsapp_conversations
         WHERE (contact_id = ? OR customer_phone = ?)
           AND status NOT IN ('resolved', 'abandoned')
         ORDER BY updated_at DESC, id DESC
         LIMIT 1",
        [$contactId, $phone]
    );
    if ($rows) {
        return (int) $rows[0]['id'];
    }

    $stmt = db()->prepare(
        "INSERT INTO ops_whatsapp_conversations (
            contact_id, customer_name, customer_phone, source, status,
            assigned_employee_id, first_customer_message_at, last_customer_message_at,
            last_message_at, phone_number_id, wa_business_account_id
         ) VALUES (?, ?, ?, 'whatsapp_business', 'awaiting_response', ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $contactId ?: null,
        $customerName ?: 'WhatsApp Customer',
        $phone,
        wa_default_frontdesk_employee_id(),
        $messageAt,
        $messageAt,
        $messageAt,
        $phoneNumberId,
        $wabaId,
    ]);

    return (int) db()->lastInsertId();
}

function wa_default_frontdesk_employee_id(): ?int
{
    $rows = ops_rows(
        "SELECT e.id
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         WHERE e.status = 'active' AND r.role_key IN ('front_desk_admin', 'owner_admin')
         ORDER BY FIELD(r.role_key, 'front_desk_admin', 'owner_admin'), e.id
         LIMIT 1"
    );

    return $rows ? (int) $rows[0]['id'] : null;
}

function wa_store_flag(int $conversationId, ?int $messageId, string $type, string $reason, string $severity = 'medium'): void
{
    $stmt = db()->prepare(
        "INSERT INTO ops_whatsapp_flagged_messages (conversation_id, message_id, flag_type, reason, severity)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$conversationId, $messageId, $type, $reason, $severity]);
}

function wa_apply_flagging_rules(int $conversationId, ?int $messageId, string $messageText): void
{
    $flag = wa_detect_flag($messageText);
    if ($flag) {
        db()->prepare('UPDATE ops_whatsapp_conversations SET complaint_flag = 1, flagged_reason = COALESCE(flagged_reason, ?), status = IF(status = "resolved", status, "escalated") WHERE id = ?')->execute([$flag, $conversationId]);
        wa_store_flag($conversationId, $messageId, 'keyword', $flag, 'high');
    }

    $rows = ops_rows(
        "SELECT COUNT(*) AS total
         FROM ops_whatsapp_messages
         WHERE conversation_id = ? AND direction = 'inbound'
           AND message_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)",
        [$conversationId]
    );
    if ((int) ($rows[0]['total'] ?? 0) >= 3) {
        $reason = 'Customer sent multiple messages without resolution.';
        db()->prepare('UPDATE ops_whatsapp_conversations SET flagged_reason = COALESCE(flagged_reason, ?) WHERE id = ?')->execute([$reason, $conversationId]);
        wa_store_flag($conversationId, $messageId, 'repeated_messages', $reason);
    }
}

function wa_store_inbound_message(array $message, array $contact, ?string $phoneNumberId, ?string $wabaId): void
{
    $waId = (string) ($message['from'] ?? ($contact['wa_id'] ?? ''));
    if ($waId === '') {
        return;
    }

    $profile = $contact['profile'] ?? [];
    $name = is_array($profile) ? (string) ($profile['name'] ?? '') : '';
    $messageAt = isset($message['timestamp']) ? date('Y-m-d H:i:s', (int) $message['timestamp']) : date('Y-m-d H:i:s');
    $type = (string) ($message['type'] ?? 'unknown');
    $text = '';
    if ($type === 'text') {
        $text = (string) ($message['text']['body'] ?? '');
    } elseif (isset($message[$type]) && is_array($message[$type])) {
        $text = '[' . $type . '] ' . (string) ($message[$type]['caption'] ?? '');
    } else {
        $text = '[' . $type . ']';
    }

    $contactId = wa_upsert_contact($waId, $name, $waId);
    $conversationId = wa_find_or_create_conversation($contactId, $name, $waId, $phoneNumberId, $wabaId, $messageAt);
    $messageId = (string) ($message['id'] ?? '');

    $stmt = db()->prepare(
        "INSERT IGNORE INTO ops_whatsapp_messages (conversation_id, contact_id, wa_message_id, direction, message_type, message_text, message_at, raw_payload, external_message_id)
         VALUES (?, ?, ?, 'inbound', ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $conversationId,
        $contactId ?: null,
        $messageId ?: null,
        $type,
        $text,
        $messageAt,
        json_encode($message, JSON_UNESCAPED_SLASHES),
        $messageId ?: null,
    ]);
    $insertedId = (int) db()->lastInsertId();

    db()->prepare(
        "UPDATE ops_whatsapp_conversations
         SET last_customer_message_at = ?, last_message_at = ?, status = IF(status IN ('resolved', 'abandoned'), status, 'awaiting_response'), updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    )->execute([$messageAt, $messageAt, $conversationId]);

    wa_apply_flagging_rules($conversationId, $insertedId > 0 ? $insertedId : null, $text);
}

function wa_store_status(array $status, ?string $phoneNumberId): void
{
    $messageId = (string) ($status['id'] ?? '');
    if ($messageId === '') {
        return;
    }

    $statusValue = (string) ($status['status'] ?? '');
    $statusAt = isset($status['timestamp']) ? date('Y-m-d H:i:s', (int) $status['timestamp']) : date('Y-m-d H:i:s');
    db()->prepare(
        "UPDATE ops_whatsapp_messages
         SET status = ?, status_at = ?, raw_payload = COALESCE(raw_payload, ?)
         WHERE wa_message_id = ? OR external_message_id = ?"
    )->execute([
        $statusValue,
        $statusAt,
        json_encode($status, JSON_UNESCAPED_SLASHES),
        $messageId,
        $messageId,
    ]);

    wa_log_webhook('message_status', $status, 'processed', null, $phoneNumberId, $messageId);
}

function wa_process_webhook_payload(array $payload): array
{
    $messages = 0;
    $statuses = 0;

    foreach (($payload['entry'] ?? []) as $entry) {
        $wabaId = isset($entry['id']) ? (string) $entry['id'] : null;
        foreach (($entry['changes'] ?? []) as $change) {
            $value = $change['value'] ?? [];
            if (!is_array($value)) {
                continue;
            }
            $metadata = $value['metadata'] ?? [];
            $phoneNumberId = is_array($metadata) ? (string) ($metadata['phone_number_id'] ?? '') : '';
            $contacts = [];
            foreach (($value['contacts'] ?? []) as $contact) {
                if (isset($contact['wa_id'])) {
                    $contacts[(string) $contact['wa_id']] = $contact;
                }
            }
            foreach (($value['messages'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $from = (string) ($message['from'] ?? '');
                wa_store_inbound_message($message, $contacts[$from] ?? [], $phoneNumberId ?: null, $wabaId);
                wa_log_webhook('message', $message, 'processed', null, $phoneNumberId ?: null, (string) ($message['id'] ?? ''));
                $messages++;
            }
            foreach (($value['statuses'] ?? []) as $status) {
                if (!is_array($status)) {
                    continue;
                }
                wa_store_status($status, $phoneNumberId ?: null);
                $statuses++;
            }
        }
    }

    wa_save_setting('last_webhook_received_at', date('Y-m-d H:i:s'));

    return ['messages' => $messages, 'statuses' => $statuses];
}

function wa_test_cloud_api(): array
{
    $phoneNumberId = wa_setting('phone_number_id');
    $token = wa_setting('access_token');
    $version = wa_setting('graph_api_version', 'v23.0');
    if ($phoneNumberId === '' || $token === '') {
        return ['ok' => false, 'message' => 'Phone Number ID and Access Token are required.'];
    }

    $url = 'https://graph.facebook.com/' . trim($version, '/') . '/' . rawurlencode($phoneNumberId);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$token}\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $statusLine = $headers[0] ?? '';
    $ok = str_contains($statusLine, '200');

    wa_save_setting('last_connection_test_at', date('Y-m-d H:i:s'));
    wa_save_setting('last_connection_test_status', $ok ? 'Connected' : 'Failed');

    return [
        'ok' => $ok,
        'message' => $ok ? 'Connected to Meta Cloud API.' : 'Meta Cloud API test failed: ' . ($statusLine ?: 'No response'),
        'body' => $body ?: '',
    ];
}
