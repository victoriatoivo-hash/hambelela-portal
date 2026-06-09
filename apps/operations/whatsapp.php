<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_role('owner_admin', 'front_desk_admin');

$pageTitle = 'WhatsApp Communication KPI | ' . APP_NAME;
$activeApp = 'operations-whatsapp';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';

const WA_STATUSES = [
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

function wa_ensure_tables(): bool
{
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_conversations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_name VARCHAR(190) NOT NULL,
                customer_phone VARCHAR(80),
                source ENUM('manual', 'whatsapp_business', 'meta_import', 'csv_import') NOT NULL DEFAULT 'manual',
                status ENUM('awaiting_response', 'follow_up', 'waiting_customer', 'waiting_stock', 'pending_payment', 'pending_courier', 'resolved', 'escalated', 'abandoned') NOT NULL DEFAULT 'awaiting_response',
                assigned_employee_id INT NULL,
                first_customer_message_at DATETIME NULL,
                first_response_at DATETIME NULL,
                last_customer_message_at DATETIME NULL,
                last_staff_response_at DATETIME NULL,
                follow_up_at DATETIME NULL,
                order_id INT NULL,
                converted_to_sale TINYINT(1) NOT NULL DEFAULT 0,
                sale_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                complaint_flag TINYINT(1) NOT NULL DEFAULT 0,
                flagged_reason VARCHAR(255),
                faq_topic VARCHAR(190),
                notes TEXT,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wa_status (status),
                INDEX idx_wa_follow_up (follow_up_at),
                INDEX idx_wa_created (created_at),
                FOREIGN KEY (assigned_employee_id) REFERENCES ops_employees(id),
                FOREIGN KEY (order_id) REFERENCES ops_orders(id),
                FOREIGN KEY (created_by) REFERENCES ops_employees(id)
            )"
        );
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_whatsapp_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                direction ENUM('inbound', 'outbound') NOT NULL,
                message_text TEXT NOT NULL,
                message_at DATETIME NOT NULL,
                employee_id INT NULL,
                external_message_id VARCHAR(120),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES ops_whatsapp_conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (employee_id) REFERENCES ops_employees(id)
            )"
        );
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

if ($ready) {
    $ready = wa_ensure_tables();
}

$tags = $ready ? ops_rows('SELECT id, name, color FROM ops_whatsapp_tags WHERE active = 1 ORDER BY name') : [];

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
        if ($action === 'save_conversation') {
            $id = (int) ($_POST['conversation_id'] ?? 0);
            $notes = ops_post_string('notes', 3000);
            $flagReason = ops_post_string('flagged_reason', 255);
            $detectedFlag = wa_detect_flag($notes);
            if ($flagReason === '' && $detectedFlag) {
                $flagReason = $detectedFlag;
            }
            $source = ops_post_string('source', 40) ?: 'manual';
            if (!in_array($source, ['manual', 'whatsapp_business', 'meta_import', 'csv_import'], true)) {
                $source = 'manual';
            }
            $complaint = isset($_POST['complaint_flag']) || str_contains(strtolower($flagReason), 'complaint') ? 1 : 0;
            $values = [
                ops_post_string('customer_name', 190) ?: 'WhatsApp Customer',
                ops_post_string('customer_phone', 80),
                $source,
                array_key_exists(ops_post_string('status', 40), WA_STATUSES) ? ops_post_string('status', 40) : 'awaiting_response',
                (int) ($_POST['assigned_employee_id'] ?? 0) ?: null,
                wa_datetime($_POST['first_customer_message_at'] ?? null),
                wa_datetime($_POST['first_response_at'] ?? null),
                wa_datetime($_POST['last_customer_message_at'] ?? null),
                wa_datetime($_POST['last_staff_response_at'] ?? null),
                wa_datetime($_POST['follow_up_at'] ?? null),
                (int) ($_POST['order_id'] ?? 0) ?: null,
                isset($_POST['converted_to_sale']) ? 1 : 0,
                max(0, (float) ($_POST['sale_amount'] ?? 0)),
                $complaint,
                $flagReason ?: null,
                ops_post_string('faq_topic', 190),
                $notes,
            ];

            if ($id > 0) {
                $stmt = db()->prepare(
                    "UPDATE ops_whatsapp_conversations
                     SET customer_name = ?, customer_phone = ?, source = ?, status = ?, assigned_employee_id = ?,
                         first_customer_message_at = ?, first_response_at = ?, last_customer_message_at = ?,
                         last_staff_response_at = ?, follow_up_at = ?, order_id = ?, converted_to_sale = ?,
                         sale_amount = ?, complaint_flag = ?, flagged_reason = ?, faq_topic = ?, notes = ?
                     WHERE id = ?"
                );
                $stmt->execute([...$values, $id]);
            } else {
                $stmt = db()->prepare(
                    "INSERT INTO ops_whatsapp_conversations (
                        customer_name, customer_phone, source, status, assigned_employee_id,
                        first_customer_message_at, first_response_at, last_customer_message_at,
                        last_staff_response_at, follow_up_at, order_id, converted_to_sale,
                        sale_amount, complaint_flag, flagged_reason, faq_topic, notes, created_by
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([...$values, ops_current_employee_id()]);
                $id = (int) db()->lastInsertId();
            }

            $selectedTags = $_POST['tag_ids'] ?? [];
            if (!is_array($selectedTags)) {
                $selectedTags = [];
            }
            db()->prepare('DELETE FROM ops_whatsapp_conversation_tags WHERE conversation_id = ?')->execute([$id]);
            $tagStmt = db()->prepare('INSERT IGNORE INTO ops_whatsapp_conversation_tags (conversation_id, tag_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $selectedTags)) as $tagId) {
                if ($tagId > 0) {
                    $tagStmt->execute([$id, $tagId]);
                }
            }

            ops_activity_log('save_whatsapp_conversation', 'ops_whatsapp_conversation', $id);
            $message = 'WhatsApp conversation saved.';
        } elseif ($action === 'add_message') {
            $conversationId = (int) ($_POST['conversation_id'] ?? 0);
            $direction = ops_post_string('direction', 20) === 'outbound' ? 'outbound' : 'inbound';
            $text = ops_post_string('message_text', 3000);
            $messageAt = wa_datetime($_POST['message_at'] ?? null) ?? date('Y-m-d H:i:s');
            if ($conversationId <= 0 || $text === '') {
                throw new RuntimeException('Select a conversation and enter a message.');
            }
            $stmt = db()->prepare(
                "INSERT INTO ops_whatsapp_messages (conversation_id, direction, message_text, message_at, employee_id)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$conversationId, $direction, $text, $messageAt, $direction === 'outbound' ? ops_current_employee_id() : null]);

            $field = $direction === 'outbound' ? 'last_staff_response_at' : 'last_customer_message_at';
            db()->prepare("UPDATE ops_whatsapp_conversations SET {$field} = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$messageAt, $conversationId]);
            $message = 'Message logged.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$filters = [
    'period' => trim((string) ($_GET['period'] ?? 'this_month')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'tag_id' => trim((string) ($_GET['tag_id'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
if (!in_array($filters['period'], ['today', 'yesterday', 'this_week', 'this_month', 'custom'], true)) {
    $filters['period'] = 'this_month';
}
[$dateFrom, $dateTo] = wa_date_filters($filters);

$where = ['DATE(c.created_at) BETWEEN ? AND ?'];
$params = [$dateFrom, $dateTo];
if ($filters['status'] !== '' && array_key_exists($filters['status'], WA_STATUSES)) {
    $where[] = 'c.status = ?';
    $params[] = $filters['status'];
}
if ($filters['search'] !== '') {
    $where[] = '(c.customer_name LIKE ? OR c.customer_phone LIKE ? OR c.faq_topic LIKE ? OR c.notes LIKE ?)';
    $like = '%' . $filters['search'] . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($filters['tag_id'] !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM ops_whatsapp_conversation_tags ct WHERE ct.conversation_id = c.id AND ct.tag_id = ?)';
    $params[] = (int) $filters['tag_id'];
}
$whereSql = implode(' AND ', $where);

$metrics = [
    'total_chats' => 0,
    'unresolved' => 0,
    'missed_followups' => 0,
    'complaints' => 0,
    'converted' => 0,
    'flagged' => 0,
    'awaiting' => 0,
    'sale_amount' => 0.0,
    'avg_first_response' => null,
    'avg_reply_time' => null,
    'longest_pending' => null,
];

if ($ready) {
    $metricRows = ops_rows(
        "SELECT
            COUNT(*) AS total_chats,
            SUM(CASE WHEN c.status NOT IN ('resolved', 'abandoned') THEN 1 ELSE 0 END) AS unresolved,
            SUM(CASE WHEN c.follow_up_at IS NOT NULL AND c.follow_up_at < NOW() AND c.status NOT IN ('resolved', 'abandoned') THEN 1 ELSE 0 END) AS missed_followups,
            SUM(c.complaint_flag) AS complaints,
            SUM(c.converted_to_sale) AS converted,
            SUM(CASE WHEN c.flagged_reason IS NOT NULL AND c.flagged_reason <> '' THEN 1 ELSE 0 END) AS flagged,
            SUM(CASE WHEN c.status = 'awaiting_response' OR (c.last_customer_message_at IS NOT NULL AND (c.last_staff_response_at IS NULL OR c.last_customer_message_at > c.last_staff_response_at)) THEN 1 ELSE 0 END) AS awaiting,
            COALESCE(SUM(c.sale_amount), 0) AS sale_amount,
            AVG(CASE WHEN c.first_customer_message_at IS NOT NULL AND c.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, c.first_customer_message_at, c.first_response_at) END) AS avg_first_response,
            AVG(CASE WHEN c.last_customer_message_at IS NOT NULL AND c.last_staff_response_at IS NOT NULL AND c.last_staff_response_at >= c.last_customer_message_at THEN TIMESTAMPDIFF(MINUTE, c.last_customer_message_at, c.last_staff_response_at) END) AS avg_reply_time,
            MAX(CASE WHEN c.last_customer_message_at IS NOT NULL AND c.status NOT IN ('resolved', 'abandoned') THEN TIMESTAMPDIFF(MINUTE, c.last_customer_message_at, NOW()) END) AS longest_pending
         FROM ops_whatsapp_conversations c
         WHERE {$whereSql}",
        $params
    );
    if ($metricRows) {
        $row = $metricRows[0];
        foreach ($metrics as $key => $default) {
            $metrics[$key] = is_float($default) ? (float) ($row[$key] ?? 0) : ($row[$key] ?? $default);
        }
    }
}

$conversionRate = (int) $metrics['total_chats'] > 0 ? ((int) $metrics['converted'] / (int) $metrics['total_chats']) * 100 : 0;
$healthScore = max(0, min(100, 100 - ((int) $metrics['missed_followups'] * 8) - ((int) $metrics['awaiting'] * 3) - ((int) $metrics['complaints'] * 6)));

$statusRows = $ready ? ops_rows(
    "SELECT c.status, COUNT(*) AS total
     FROM ops_whatsapp_conversations c
     WHERE {$whereSql}
     GROUP BY c.status
     ORDER BY total DESC",
    $params
) : [];

$faqRows = $ready ? ops_rows(
    "SELECT COALESCE(NULLIF(c.faq_topic, ''), 'Uncategorised') AS topic, COUNT(*) AS total
     FROM ops_whatsapp_conversations c
     WHERE {$whereSql}
     GROUP BY topic
     ORDER BY total DESC, topic
     LIMIT 8",
    $params
) : [];

$flaggedRows = $ready ? ops_rows(
    "SELECT c.id, c.customer_name, c.customer_phone, c.status, c.flagged_reason, c.follow_up_at, c.created_at
     FROM ops_whatsapp_conversations c
     WHERE {$whereSql} AND (c.flagged_reason IS NOT NULL AND c.flagged_reason <> '' OR c.complaint_flag = 1 OR (c.follow_up_at IS NOT NULL AND c.follow_up_at < NOW() AND c.status NOT IN ('resolved', 'abandoned')))
     ORDER BY COALESCE(c.follow_up_at, c.created_at) ASC
     LIMIT 12",
    $params
) : [];

$conversationRows = $ready ? ops_rows(
    "SELECT c.*,
            e.full_name AS assigned_name,
            (
                SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
                FROM ops_whatsapp_conversation_tags ct
                JOIN ops_whatsapp_tags t ON t.id = ct.tag_id
                WHERE ct.conversation_id = c.id
            ) AS tag_names
     FROM ops_whatsapp_conversations c
     LEFT JOIN ops_employees e ON e.id = c.assigned_employee_id
     WHERE {$whereSql}
     ORDER BY
        CASE WHEN c.status = 'awaiting_response' THEN 0 WHEN c.status = 'follow_up' THEN 1 ELSE 2 END,
        COALESCE(c.follow_up_at, c.last_customer_message_at, c.created_at) DESC
     LIMIT 80",
    $params
) : [];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module whatsapp-module">
    <section class="module-header cost-system-header">
        <div>
            <p class="eyebrow">Customer Communication</p>
            <h1>WhatsApp KPI Dashboard</h1>
            <p>Track response speed, unresolved chats, follow-ups, repeated questions and customer-to-sale conversion for front desk communication.</p>
        </div>
        <div class="actions">
            <a class="button" href="index.php"><i data-lucide="arrow-left"></i> Operations</a>
            <a class="button primary" href="#new-conversation"><i data-lucide="message-circle-plus"></i> Log conversation</a>
        </div>
    </section>

    <?php ops_nav('whatsapp'); ?>
    <?php if (!$ready): ?>
        <section class="ops-alert"><strong>Database setup needed.</strong> Import <code>operations-whatsapp-kpi-migration.sql</code> or allow the app database user to create tables automatically.</section>
    <?php endif; ?>
    <?php ops_flash($message, $messageType); ?>

    <form class="panel report-filter-panel" method="get">
        <label>Period
            <select name="period">
                <?php ops_select_options(['today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'this_month' => 'This month', 'custom' => 'Custom'], $filters['period']); ?>
            </select>
        </label>
        <label>From <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>To <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Status
            <select name="status"><option value="">All statuses</option><?php ops_select_options(WA_STATUSES, $filters['status']); ?></select>
        </label>
        <label>Tag
            <select name="tag_id">
                <option value="">All tags</option>
                <?php foreach ($tags as $tag): ?>
                    <option value="<?= (int) $tag['id'] ?>" <?= (string) $tag['id'] === $filters['tag_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $tag['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Search <input name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Customer, phone, FAQ, note"></label>
        <button class="button primary" type="submit"><i data-lucide="filter"></i> Apply</button>
    </form>

    <section class="work-metric-grid whatsapp-kpi-grid">
        <article class="work-metric-card metric-blue"><span class="metric-icon"><i data-lucide="messages-square"></i></span><div><span class="metric-title">Chats Handled</span><strong><?= number_format((int) $metrics['total_chats']) ?></strong><small><?= htmlspecialchars($dateFrom . ' to ' . $dateTo, ENT_QUOTES, 'UTF-8') ?></small></div></article>
        <article class="work-metric-card metric-orange"><span class="metric-icon"><i data-lucide="clock-3"></i></span><div><span class="metric-title">Avg First Response</span><strong><?= wa_minutes($metrics['avg_first_response'] !== null ? (float) $metrics['avg_first_response'] : null) ?></strong><small>Customer wait time</small></div></article>
        <article class="work-metric-card metric-purple"><span class="metric-icon"><i data-lucide="reply"></i></span><div><span class="metric-title">Avg Reply Time</span><strong><?= wa_minutes($metrics['avg_reply_time'] !== null ? (float) $metrics['avg_reply_time'] : null) ?></strong><small>Latest response gap</small></div></article>
        <article class="work-metric-card metric-red"><span class="metric-icon"><i data-lucide="message-square-warning"></i></span><div><span class="metric-title">Awaiting Response</span><strong><?= number_format((int) $metrics['awaiting']) ?></strong><small>Needs attention</small></div></article>
        <article class="work-metric-card metric-pink"><span class="metric-icon"><i data-lucide="bell-ring"></i></span><div><span class="metric-title">Missed Follow-ups</span><strong><?= number_format((int) $metrics['missed_followups']) ?></strong><small>Past reminder time</small></div></article>
        <article class="work-metric-card metric-green"><span class="metric-icon"><i data-lucide="badge-dollar-sign"></i></span><div><span class="metric-title">Chat to Sale</span><strong><?= number_format($conversionRate, 1) ?>%</strong><small><?= wa_money((float) $metrics['sale_amount']) ?> sales logged</small></div></article>
        <article class="work-metric-card metric-red"><span class="metric-icon"><i data-lucide="flag"></i></span><div><span class="metric-title">Flagged Chats</span><strong><?= number_format((int) $metrics['flagged']) ?></strong><small><?= number_format((int) $metrics['complaints']) ?> complaints</small></div></article>
        <article class="work-metric-card metric-teal"><span class="metric-icon"><i data-lucide="activity"></i></span><div><span class="metric-title">Communication Health</span><strong><?= number_format($healthScore) ?>%</strong><small>Based on delays and flags</small></div></article>
    </section>

    <section class="whatsapp-insight-grid">
        <article class="panel">
            <div class="section-row"><h2>Conversation status</h2><span class="status">Live view</span></div>
            <div class="mini-chart-list">
                <?php foreach ($statusRows as $row): ?>
                    <?php $width = (int) $metrics['total_chats'] > 0 ? ((int) $row['total'] / (int) $metrics['total_chats']) * 100 : 0; ?>
                    <div class="mini-chart-row">
                        <span><?= htmlspecialchars(WA_STATUSES[(string) $row['status']] ?? (string) $row['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= number_format((int) $row['total']) ?></strong>
                        <i style="width: <?= number_format($width, 2, '.', '') ?>%"></i>
                    </div>
                <?php endforeach; ?>
                <?php if (!$statusRows): ?><p class="empty-state">No conversations recorded for this period.</p><?php endif; ?>
            </div>
        </article>
        <article class="panel">
            <div class="section-row"><h2>Frequently asked questions</h2><span class="status">Keywords</span></div>
            <div class="mini-chart-list">
                <?php foreach ($faqRows as $row): ?>
                    <?php $width = (int) $metrics['total_chats'] > 0 ? ((int) $row['total'] / (int) $metrics['total_chats']) * 100 : 0; ?>
                    <div class="mini-chart-row">
                        <span><?= htmlspecialchars((string) $row['topic'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= number_format((int) $row['total']) ?></strong>
                        <i style="width: <?= number_format($width, 2, '.', '') ?>%"></i>
                    </div>
                <?php endforeach; ?>
                <?php if (!$faqRows): ?><p class="empty-state">Add FAQ topics as conversations are reviewed.</p><?php endif; ?>
            </div>
        </article>
    </section>

    <section class="panel">
        <div class="section-row">
            <div>
                <h2>Flagged conversation review</h2>
                <p>Owner/admin view for overdue follow-ups, complaints and delayed customer communication.</p>
            </div>
            <span class="status"><?= number_format(count($flaggedRows)) ?> needs review</span>
        </div>
        <div class="table-scroll">
            <table class="data-table ops-table">
                <thead><tr><th>Customer</th><th>Status</th><th>Reason</th><th>Follow-up</th><th>Created</th></tr></thead>
                <tbody>
                <?php foreach ($flaggedRows as $row): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string) $row['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong><br><small><?= htmlspecialchars((string) $row['customer_phone'], ENT_QUOTES, 'UTF-8') ?></small></td>
                        <td><span class="status wa-status-<?= htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(WA_STATUSES[(string) $row['status']] ?? (string) $row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string) ($row['flagged_reason'] ?: 'Missed follow-up / overdue conversation'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($row['follow_up_at'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$flaggedRows): ?><tr><td colspan="5">No flagged conversations in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="whatsapp-workspace-grid">
        <article class="panel" id="new-conversation">
            <div class="section-row"><h2>Log or update conversation</h2><span class="status">Manual now, API-ready later</span></div>
            <form class="form-grid" method="post">
                <input type="hidden" name="action" value="save_conversation">
                <label>Conversation ID<input name="conversation_id" type="number" min="0" placeholder="Leave blank for new"></label>
                <label>Customer name<input name="customer_name" required placeholder="Customer name"></label>
                <label>Phone<input name="customer_phone" placeholder="+264..."></label>
                <label>Status<select name="status"><?php ops_select_options(WA_STATUSES, 'awaiting_response'); ?></select></label>
                <label>Assigned to<select name="assigned_employee_id"><?php ops_employee_options(null, true); ?></select></label>
                <label>FAQ topic<input name="faq_topic" placeholder="Delivery cost, stock, location"></label>
                <label>First customer message<input name="first_customer_message_at" type="datetime-local"></label>
                <label>First response<input name="first_response_at" type="datetime-local"></label>
                <label>Last customer message<input name="last_customer_message_at" type="datetime-local"></label>
                <label>Last staff response<input name="last_staff_response_at" type="datetime-local"></label>
                <label>Follow-up reminder<input name="follow_up_at" type="datetime-local"></label>
                <label>Linked order ID<input name="order_id" type="number" min="0" placeholder="Optional"></label>
                <label>Sale amount<input name="sale_amount" type="number" step="0.01" min="0" placeholder="0.00"></label>
                <label class="checkbox-line"><input name="converted_to_sale" type="checkbox"> Converted to sale</label>
                <label class="checkbox-line"><input name="complaint_flag" type="checkbox"> Customer complaint</label>
                <label>Flag reason<input name="flagged_reason" placeholder="Still waiting, complaint, overdue"></label>
                <fieldset class="wa-tag-picker">
                    <legend>Tags</legend>
                    <?php foreach ($tags as $tag): ?>
                        <label><input type="checkbox" name="tag_ids[]" value="<?= (int) $tag['id'] ?>"> <?= htmlspecialchars((string) $tag['name'], ENT_QUOTES, 'UTF-8') ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <label class="wide">Notes<textarea name="notes" rows="4" placeholder="Summary, issue, customer question, next action"></textarea></label>
                <button class="button primary" type="submit"><i data-lucide="save"></i> Save conversation</button>
            </form>
        </article>

        <article class="panel">
            <div class="section-row"><h2>Log message</h2><span class="status">Response history</span></div>
            <form class="form-grid compact-form" method="post">
                <input type="hidden" name="action" value="add_message">
                <label>Conversation ID<input name="conversation_id" type="number" min="1" required></label>
                <label>Direction<select name="direction"><option value="inbound">Customer message</option><option value="outbound">Staff response</option></select></label>
                <label>Time<input name="message_at" type="datetime-local"></label>
                <label class="wide">Message<textarea name="message_text" rows="5" required placeholder="Paste or summarize the WhatsApp message"></textarea></label>
                <button class="button primary" type="submit"><i data-lucide="message-square-plus"></i> Log message</button>
            </form>
        </article>
    </section>

    <section class="panel">
        <div class="section-row">
            <div>
                <h2>Conversation list</h2>
                <p>Operational customer communication list for front desk follow-up and owner review.</p>
            </div>
        </div>
        <div class="table-scroll">
            <table class="data-table ops-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Assigned</th>
                        <th>Tags</th>
                        <th>FAQ</th>
                        <th>First response</th>
                        <th>Last pending</th>
                        <th>Sale</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($conversationRows as $row): ?>
                    <?php
                    $firstResponse = null;
                    if ($row['first_customer_message_at'] && $row['first_response_at']) {
                        $firstResponse = (float) ((strtotime((string) $row['first_response_at']) - strtotime((string) $row['first_customer_message_at'])) / 60);
                    }
                    $lastPending = null;
                    if ($row['last_customer_message_at'] && (!$row['last_staff_response_at'] || $row['last_customer_message_at'] > $row['last_staff_response_at'])) {
                        $lastPending = (float) ((time() - strtotime((string) $row['last_customer_message_at'])) / 60);
                    }
                    ?>
                    <tr>
                        <td>#<?= (int) $row['id'] ?></td>
                        <td><strong><?= htmlspecialchars((string) $row['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong><br><small><?= htmlspecialchars((string) $row['customer_phone'], ENT_QUOTES, 'UTF-8') ?></small></td>
                        <td><span class="status wa-status-<?= htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(WA_STATUSES[(string) $row['status']] ?? (string) $row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string) ($row['assigned_name'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($row['tag_names'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($row['faq_topic'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= wa_minutes($firstResponse) ?></td>
                        <td><?= wa_minutes($lastPending) ?></td>
                        <td><?= ((int) $row['converted_to_sale'] === 1 ? 'Yes - ' : 'No - ') . wa_money((float) $row['sale_amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$conversationRows): ?><tr><td colspan="9">No WhatsApp conversations recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
