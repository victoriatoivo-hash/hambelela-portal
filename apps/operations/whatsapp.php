<?php

declare(strict_types=1);

require_once __DIR__ . '/whatsapp-service.php';

require_role('owner_admin', 'front_desk_admin');

$pageTitle = 'WhatsApp Communication KPI | ' . APP_NAME;
$activeApp = 'operations-whatsapp';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$statuses = wa_statuses();

if ($ready) {
    $ready = wa_ensure_tables();
}

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
            if (!in_array($source, wa_sources(), true)) {
                $source = 'manual';
            }
            $status = ops_post_string('status', 40);
            if (!array_key_exists($status, $statuses)) {
                $status = 'awaiting_response';
            }
            $complaint = isset($_POST['complaint_flag']) || str_contains(strtolower($flagReason), 'complaint') ? 1 : 0;
            $values = [
                ops_post_string('customer_name', 190) ?: 'WhatsApp Customer',
                ops_post_string('customer_phone', 80),
                $source,
                $status,
                (int) ($_POST['assigned_employee_id'] ?? 0) ?: null,
                wa_datetime($_POST['first_customer_message_at'] ?? null),
                wa_datetime($_POST['first_response_at'] ?? null),
                wa_datetime($_POST['last_customer_message_at'] ?? null),
                wa_datetime($_POST['last_staff_response_at'] ?? null),
                wa_datetime($_POST['last_customer_message_at'] ?? null) ?: wa_datetime($_POST['last_staff_response_at'] ?? null),
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
                         last_staff_response_at = ?, last_message_at = ?, follow_up_at = ?, order_id = ?,
                         converted_to_sale = ?, sale_amount = ?, complaint_flag = ?, flagged_reason = ?,
                         faq_topic = ?, notes = ?
                     WHERE id = ?"
                );
                $stmt->execute([...$values, $id]);
            } else {
                $stmt = db()->prepare(
                    "INSERT INTO ops_whatsapp_conversations (
                        customer_name, customer_phone, source, status, assigned_employee_id,
                        first_customer_message_at, first_response_at, last_customer_message_at,
                        last_staff_response_at, last_message_at, follow_up_at, order_id,
                        converted_to_sale, sale_amount, complaint_flag, flagged_reason, faq_topic, notes, created_by
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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

            $followUpAt = wa_datetime($_POST['follow_up_at'] ?? null);
            if ($followUpAt) {
                db()->prepare(
                    "INSERT INTO ops_whatsapp_followups (conversation_id, due_at, reason, created_by)
                     VALUES (?, ?, ?, ?)"
                )->execute([$id, $followUpAt, ops_post_string('follow_up_reason', 255) ?: 'Follow-up reminder', ops_current_employee_id()]);
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
                "INSERT INTO ops_whatsapp_messages (conversation_id, direction, message_type, message_text, message_at, employee_id)
                 VALUES (?, ?, 'manual', ?, ?, ?)"
            );
            $stmt->execute([$conversationId, $direction, $text, $messageAt, $direction === 'outbound' ? ops_current_employee_id() : null]);

            $field = $direction === 'outbound' ? 'last_staff_response_at' : 'last_customer_message_at';
            $firstResponse = $direction === 'outbound' ? ', first_response_at = COALESCE(first_response_at, ?)' : '';
            $params = $direction === 'outbound'
                ? [$messageAt, $messageAt, $messageAt, $conversationId]
                : [$messageAt, $messageAt, $conversationId];
            db()->prepare("UPDATE ops_whatsapp_conversations SET {$field} = ?, last_message_at = ?{$firstResponse}, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute($params);

            if ($direction === 'inbound') {
                wa_apply_flagging_rules($conversationId, (int) db()->lastInsertId(), $text);
            }
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

$tags = $ready ? ops_rows('SELECT id, name, color FROM ops_whatsapp_tags WHERE active = 1 ORDER BY name') : [];
$where = ['DATE(c.created_at) BETWEEN ? AND ?'];
$params = [$dateFrom, $dateTo];
if ($filters['status'] !== '' && array_key_exists($filters['status'], $statuses)) {
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
    'today_chats' => 0,
    'month_chats' => 0,
    'unresolved' => 0,
    'missed_followups' => 0,
    'complaints' => 0,
    'converted' => 0,
    'flagged' => 0,
    'awaiting' => 0,
    'resolved' => 0,
    'sale_amount' => 0.0,
    'avg_first_response' => null,
    'avg_reply_time' => null,
    'longest_pending' => null,
];

if ($ready) {
    $metricRows = ops_rows(
        "SELECT
            COUNT(*) AS total_chats,
            SUM(CASE WHEN DATE(c.created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_chats,
            SUM(CASE WHEN c.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS month_chats,
            SUM(CASE WHEN c.status NOT IN ('resolved', 'abandoned') THEN 1 ELSE 0 END) AS unresolved,
            SUM(CASE WHEN c.status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
            SUM(CASE WHEN c.follow_up_at IS NOT NULL AND c.follow_up_at < NOW() AND c.status NOT IN ('resolved', 'abandoned') THEN 1 ELSE 0 END) AS missed_followups,
            SUM(c.complaint_flag) AS complaints,
            SUM(c.converted_to_sale) AS converted,
            SUM(CASE WHEN c.flagged_reason IS NOT NULL AND c.flagged_reason <> '' THEN 1 ELSE 0 END) AS flagged,
            SUM(CASE WHEN c.status = 'awaiting_response' OR (c.last_customer_message_at IS NOT NULL AND (c.last_staff_response_at IS NULL OR c.last_customer_message_at > c.last_staff_response_at)) THEN 1 ELSE 0 END) AS awaiting,
            COALESCE(SUM(c.sale_amount), 0) AS sale_amount,
            AVG(CASE WHEN c.first_customer_message_at IS NOT NULL AND c.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, c.first_customer_message_at, c.first_response_at) END) AS avg_first_response,
            MAX(CASE WHEN c.last_customer_message_at IS NOT NULL AND c.status NOT IN ('resolved', 'abandoned') THEN TIMESTAMPDIFF(MINUTE, c.last_customer_message_at, NOW()) END) AS longest_pending
         FROM ops_whatsapp_conversations c
         WHERE {$whereSql}",
        $params
    );
    if ($metricRows) {
        foreach ($metrics as $key => $default) {
            $metrics[$key] = $metricRows[0][$key] ?? $default;
        }
    }
    $replyRows = ops_rows(
        "SELECT AVG(TIMESTAMPDIFF(MINUTE, inbound.message_at, outbound.message_at)) AS avg_reply_time
         FROM ops_whatsapp_messages outbound
         JOIN ops_whatsapp_conversations c ON c.id = outbound.conversation_id
         JOIN ops_whatsapp_messages inbound ON inbound.conversation_id = c.id
          AND inbound.direction = 'inbound'
          AND inbound.message_at = (
              SELECT MAX(prev.message_at)
              FROM ops_whatsapp_messages prev
              WHERE prev.conversation_id = c.id
                AND prev.direction = 'inbound'
                AND prev.message_at <= outbound.message_at
          )
         WHERE outbound.direction = 'outbound'
           AND {$whereSql}",
        $params
    );
    $metrics['avg_reply_time'] = $replyRows[0]['avg_reply_time'] ?? null;
}

$conversionRate = (int) $metrics['total_chats'] > 0 ? ((int) $metrics['converted'] / (int) $metrics['total_chats']) * 100 : 0;
$healthScore = max(0, min(100, 100 - ((int) $metrics['missed_followups'] * 8) - ((int) $metrics['awaiting'] * 3) - ((int) $metrics['complaints'] * 6)));
$lastWebhook = wa_setting('last_webhook_received_at', 'Never');
$connectionStatus = wa_setting('last_connection_test_status', 'Not tested');

$statusRows = $ready ? ops_rows(
    "SELECT c.status, COUNT(*) AS total FROM ops_whatsapp_conversations c WHERE {$whereSql} GROUP BY c.status ORDER BY total DESC",
    $params
) : [];
$faqRows = $ready ? ops_rows(
    "SELECT COALESCE(NULLIF(c.faq_topic, ''), 'Uncategorised') AS topic, COUNT(*) AS total
     FROM ops_whatsapp_conversations c WHERE {$whereSql} GROUP BY topic ORDER BY total DESC, topic LIMIT 8",
    $params
) : [];
$flaggedRows = $ready ? ops_rows(
    "SELECT c.id, c.customer_name, c.customer_phone, c.status, c.flagged_reason, c.follow_up_at, c.created_at
     FROM ops_whatsapp_conversations c
     WHERE {$whereSql} AND (c.flagged_reason IS NOT NULL AND c.flagged_reason <> '' OR c.complaint_flag = 1 OR (c.follow_up_at IS NOT NULL AND c.follow_up_at < NOW() AND c.status NOT IN ('resolved', 'abandoned')))
     ORDER BY COALESCE(c.follow_up_at, c.created_at) ASC LIMIT 12",
    $params
) : [];
$conversationRows = $ready ? ops_rows(
    "SELECT c.*,
            e.full_name AS assigned_name,
            (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM ops_whatsapp_conversation_tags ct JOIN ops_whatsapp_tags t ON t.id = ct.tag_id WHERE ct.conversation_id = c.id) AS tag_names,
            (SELECT COUNT(*) FROM ops_whatsapp_messages m WHERE m.conversation_id = c.id) AS message_count
     FROM ops_whatsapp_conversations c
     LEFT JOIN ops_employees e ON e.id = c.assigned_employee_id
     WHERE {$whereSql}
     ORDER BY CASE WHEN c.status = 'awaiting_response' THEN 0 WHEN c.status = 'follow_up' THEN 1 ELSE 2 END, COALESCE(c.follow_up_at, c.last_message_at, c.created_at) DESC
     LIMIT 80",
    $params
) : [];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module whatsapp-module">
    <section class="module-header cost-system-header whatsapp-hero">
        <div>
            <p class="eyebrow">Customer Communication</p>
            <h1>WhatsApp KPI Dashboard</h1>
            <p>Real WhatsApp Business webhook data, response speed, unresolved chats, follow-ups, FAQs and chat-to-sale conversion.</p>
        </div>
        <div class="actions">
            <a class="button" href="index.php"><i data-lucide="arrow-left"></i> Operations</a>
            <?php if (user_has_role('owner_admin')): ?><a class="button" href="whatsapp-settings.php"><i data-lucide="settings"></i> WhatsApp settings</a><?php endif; ?>
            <a class="button primary" href="#new-conversation"><i data-lucide="message-circle-plus"></i> Manual fallback</a>
        </div>
    </section>

    <?php ops_nav('whatsapp'); ?>
    <?php if (!$ready): ?>
        <section class="ops-alert"><strong>Database setup needed.</strong> Import <code>operations-whatsapp-kpi-migration.sql</code> or allow the app database user to create tables automatically.</section>
    <?php endif; ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="panel whatsapp-api-strip">
        <div><span>Webhook URL</span><strong><?= htmlspecialchars(wa_webhook_url(), ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Connection</span><strong><?= htmlspecialchars($connectionStatus, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Last webhook</span><strong><?= htmlspecialchars($lastWebhook, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Mode</span><strong>API-first, manual fallback</strong></div>
    </section>

    <form class="panel report-filter-panel" method="get">
        <label>Period<select name="period"><?php ops_select_options(['today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'this_month' => 'This month', 'custom' => 'Custom'], $filters['period']); ?></select></label>
        <label>From <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>To <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Status<select name="status"><option value="">All statuses</option><?php ops_select_options($statuses, $filters['status']); ?></select></label>
        <label>Tag<select name="tag_id"><option value="">All tags</option><?php foreach ($tags as $tag): ?><option value="<?= (int) $tag['id'] ?>" <?= (string) $tag['id'] === $filters['tag_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $tag['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
        <label>Search <input name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Customer, phone, FAQ, note"></label>
        <button class="button primary" type="submit"><i data-lucide="filter"></i> Apply</button>
    </form>

    <section class="work-metric-grid whatsapp-kpi-grid">
        <article class="work-metric-card metric-blue"><span class="metric-icon"><i data-lucide="messages-square"></i></span><div><span class="metric-title">Chats Handled</span><strong><?= number_format((int) $metrics['total_chats']) ?></strong><small><?= number_format((int) $metrics['today_chats']) ?> today</small></div></article>
        <article class="work-metric-card metric-orange"><span class="metric-icon"><i data-lucide="clock-3"></i></span><div><span class="metric-title">Avg First Response</span><strong><?= wa_minutes($metrics['avg_first_response'] !== null ? (float) $metrics['avg_first_response'] : null) ?></strong><small>Customer wait time</small></div></article>
        <article class="work-metric-card metric-purple"><span class="metric-icon"><i data-lucide="reply"></i></span><div><span class="metric-title">Avg Reply Time</span><strong><?= wa_minutes($metrics['avg_reply_time'] !== null ? (float) $metrics['avg_reply_time'] : null) ?></strong><small>Message-to-reply gap</small></div></article>
        <article class="work-metric-card metric-red"><span class="metric-icon"><i data-lucide="message-square-warning"></i></span><div><span class="metric-title">Awaiting Response</span><strong><?= number_format((int) $metrics['awaiting']) ?></strong><small>Needs attention</small></div></article>
        <article class="work-metric-card metric-pink"><span class="metric-icon"><i data-lucide="bell-ring"></i></span><div><span class="metric-title">Missed Follow-ups</span><strong><?= number_format((int) $metrics['missed_followups']) ?></strong><small>Past reminder time</small></div></article>
        <article class="work-metric-card metric-green"><span class="metric-icon"><i data-lucide="badge-dollar-sign"></i></span><div><span class="metric-title">Chat to Sale</span><strong><?= number_format($conversionRate, 1) ?>%</strong><small><?= wa_money((float) $metrics['sale_amount']) ?> sales logged</small></div></article>
        <article class="work-metric-card metric-red"><span class="metric-icon"><i data-lucide="flag"></i></span><div><span class="metric-title">Flagged Chats</span><strong><?= number_format((int) $metrics['flagged']) ?></strong><small><?= number_format((int) $metrics['complaints']) ?> complaints</small></div></article>
        <article class="work-metric-card metric-teal"><span class="metric-icon"><i data-lucide="activity"></i></span><div><span class="metric-title">Communication Health</span><strong><?= number_format($healthScore) ?>%</strong><small><?= number_format((int) $metrics['resolved']) ?> resolved</small></div></article>
    </section>

    <section class="whatsapp-insight-grid">
        <article class="panel">
            <div class="section-row"><h2>Conversation status</h2><span class="status">Live API data</span></div>
            <div class="mini-chart-list">
                <?php foreach ($statusRows as $row): ?>
                    <?php $width = (int) $metrics['total_chats'] > 0 ? ((int) $row['total'] / (int) $metrics['total_chats']) * 100 : 0; ?>
                    <div class="mini-chart-row"><span><?= htmlspecialchars($statuses[(string) $row['status']] ?? (string) $row['status'], ENT_QUOTES, 'UTF-8') ?></span><strong><?= number_format((int) $row['total']) ?></strong><i style="width: <?= number_format($width, 2, '.', '') ?>%"></i></div>
                <?php endforeach; ?>
                <?php if (!$statusRows): ?><p class="empty-state">No conversations recorded for this period.</p><?php endif; ?>
            </div>
        </article>
        <article class="panel">
            <div class="section-row"><h2>Frequently asked questions</h2><span class="status">Keywords</span></div>
            <div class="wa-faq-cloud">
                <?php foreach ($faqRows as $row): ?><span><?= htmlspecialchars((string) $row['topic'], ENT_QUOTES, 'UTF-8') ?> <strong><?= number_format((int) $row['total']) ?></strong></span><?php endforeach; ?>
                <?php if (!$faqRows): ?><p class="empty-state">Incoming WhatsApp messages can be tagged with FAQ topics as they are reviewed.</p><?php endif; ?>
            </div>
        </article>
    </section>

    <section class="panel">
        <div class="section-row"><div><h2>Flagged conversation review</h2><p>Overdue follow-ups, complaints and delayed customer communication.</p></div><span class="status"><?= number_format(count($flaggedRows)) ?> needs review</span></div>
        <div class="table-scroll">
            <table class="data-table ops-table">
                <thead><tr><th>Customer</th><th>Status</th><th>Reason</th><th>Follow-up</th><th>Created</th></tr></thead>
                <tbody>
                <?php foreach ($flaggedRows as $row): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string) $row['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong><br><small><?= htmlspecialchars((string) $row['customer_phone'], ENT_QUOTES, 'UTF-8') ?></small></td>
                        <td><span class="status wa-status-<?= htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statuses[(string) $row['status']] ?? (string) $row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
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
            <div class="section-row"><h2>Manual fallback</h2><span class="status">Secondary workflow</span></div>
            <form class="form-grid" method="post">
                <input type="hidden" name="action" value="save_conversation">
                <label>Conversation ID<input name="conversation_id" type="number" min="0" placeholder="Leave blank for new"></label>
                <label>Customer name<input name="customer_name" required placeholder="Customer name"></label>
                <label>Phone<input name="customer_phone" placeholder="+264..."></label>
                <label>Status<select name="status"><?php ops_select_options($statuses, 'awaiting_response'); ?></select></label>
                <label>Assigned to<select name="assigned_employee_id"><?php ops_employee_options(null, true); ?></select></label>
                <label>FAQ topic<input name="faq_topic" placeholder="Delivery cost, stock, location"></label>
                <label>First customer message<input name="first_customer_message_at" type="datetime-local"></label>
                <label>First response<input name="first_response_at" type="datetime-local"></label>
                <label>Last customer message<input name="last_customer_message_at" type="datetime-local"></label>
                <label>Last staff response<input name="last_staff_response_at" type="datetime-local"></label>
                <label>Follow-up reminder<input name="follow_up_at" type="datetime-local"></label>
                <label>Follow-up reason<input name="follow_up_reason" placeholder="Pending payment, courier update"></label>
                <label>Linked order ID<input name="order_id" type="number" min="0" placeholder="Optional"></label>
                <label>Sale amount<input name="sale_amount" type="number" step="0.01" min="0" placeholder="0.00"></label>
                <label class="checkbox-line"><input name="converted_to_sale" type="checkbox"> Converted to sale</label>
                <label class="checkbox-line"><input name="complaint_flag" type="checkbox"> Customer complaint</label>
                <label>Flag reason<input name="flagged_reason" placeholder="Still waiting, complaint, overdue"></label>
                <fieldset class="wa-tag-picker"><legend>Tags</legend><?php foreach ($tags as $tag): ?><label><input type="checkbox" name="tag_ids[]" value="<?= (int) $tag['id'] ?>"> <?= htmlspecialchars((string) $tag['name'], ENT_QUOTES, 'UTF-8') ?></label><?php endforeach; ?></fieldset>
                <label class="wide">Notes<textarea name="notes" rows="4" placeholder="Summary, issue, customer question, next action"></textarea></label>
                <button class="button primary" type="submit"><i data-lucide="save"></i> Save manual conversation</button>
            </form>
        </article>
        <article class="panel">
            <div class="section-row"><h2>Manual message log</h2><span class="status">Use when API data is unavailable</span></div>
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
        <div class="section-row"><div><h2>Conversation list</h2><p>Webhook and manual customer communication records.</p></div></div>
        <div class="table-scroll">
            <table class="data-table ops-table">
                <thead><tr><th>ID</th><th>Customer</th><th>Status</th><th>Assigned</th><th>Tags</th><th>FAQ</th><th>Messages</th><th>First response</th><th>Last pending</th><th>Sale</th></tr></thead>
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
                        <td><span class="status wa-status-<?= htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statuses[(string) $row['status']] ?? (string) $row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string) ($row['assigned_name'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($row['tag_names'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($row['faq_topic'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((int) $row['message_count']) ?></td>
                        <td><?= wa_minutes($firstResponse) ?></td>
                        <td><?= wa_minutes($lastPending) ?></td>
                        <td><?= ((int) $row['converted_to_sale'] === 1 ? 'Yes - ' : 'No - ') . wa_money((float) $row['sale_amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$conversationRows): ?><tr><td colspan="10">No WhatsApp conversations recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
