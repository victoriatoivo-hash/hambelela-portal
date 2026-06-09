<?php

declare(strict_types=1);

require_once __DIR__ . '/whatsapp-service.php';

require_role('owner_admin');

$pageTitle = 'Meta Communications Settings | ' . APP_NAME;
$activeApp = 'operations-whatsapp';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$testResult = null;

if ($ready) {
    $ready = wa_ensure_tables();
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
        if ($action === 'save_settings') {
            wa_save_setting('meta_business_id', ops_post_string('meta_business_id', 120));
            wa_save_setting('waba_id', ops_post_string('waba_id', 120));
            wa_save_setting('phone_number_id', ops_post_string('phone_number_id', 120));
            wa_save_setting('facebook_page_id', ops_post_string('facebook_page_id', 120));
            wa_save_setting('instagram_business_account_id', ops_post_string('instagram_business_account_id', 120));
            wa_save_setting('graph_api_version', ops_post_string('graph_api_version', 20) ?: 'v23.0');
            wa_save_setting('verify_token', ops_post_string('verify_token', 190), true);
            $accessToken = trim((string) ($_POST['access_token'] ?? ''));
            if ($accessToken !== '' && $accessToken !== '__KEEP__') {
                wa_save_setting('access_token', $accessToken, true);
            }
            $pageAccessToken = trim((string) ($_POST['page_access_token'] ?? ''));
            if ($pageAccessToken !== '' && $pageAccessToken !== '__KEEP__') {
                wa_save_setting('page_access_token', $pageAccessToken, true);
            }
            wa_save_setting('response_overdue_minutes', (string) max(5, (int) ($_POST['response_overdue_minutes'] ?? 60)));
            wa_save_setting('conversation_overdue_hours', (string) max(1, (int) ($_POST['conversation_overdue_hours'] ?? 12)));
            wa_save_setting('complaint_keywords', substr(trim((string) ($_POST['complaint_keywords'] ?? '')), 0, 2000));
            $message = 'Meta communications settings saved.';
        } elseif ($action === 'test_connection') {
            $testResult = wa_test_cloud_api();
            $message = $testResult['message'];
            $messageType = $testResult['ok'] ? 'success' : 'error';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$settings = [
    'meta_business_id' => wa_setting('meta_business_id'),
    'waba_id' => wa_setting('waba_id'),
    'phone_number_id' => wa_setting('phone_number_id'),
    'facebook_page_id' => wa_setting('facebook_page_id'),
    'instagram_business_account_id' => wa_setting('instagram_business_account_id'),
    'graph_api_version' => wa_setting('graph_api_version', 'v23.0'),
    'verify_token' => wa_setting('verify_token'),
    'access_token' => wa_setting('access_token'),
    'page_access_token' => wa_setting('page_access_token'),
    'response_overdue_minutes' => wa_setting('response_overdue_minutes', '60'),
    'conversation_overdue_hours' => wa_setting('conversation_overdue_hours', '12'),
    'complaint_keywords' => wa_setting('complaint_keywords', 'hello???, bad service, ignored, still waiting, unprofessional, anyone responding, no response, complaint, angry'),
    'last_webhook_received_at' => wa_setting('last_webhook_received_at', 'Never'),
    'last_instagram_webhook_received_at' => wa_setting('last_instagram_webhook_received_at', 'Never'),
    'last_facebook_webhook_received_at' => wa_setting('last_facebook_webhook_received_at', 'Never'),
    'last_connection_test_at' => wa_setting('last_connection_test_at', 'Never'),
    'last_connection_test_status' => wa_setting('last_connection_test_status', 'Not tested'),
];

$logs = $ready && ops_table_exists('ops_whatsapp_webhook_logs') ? ops_rows(
    "SELECT platform, event_type, phone_number_id, wa_message_id, processed_status, error_message, received_at
     FROM ops_whatsapp_webhook_logs
     ORDER BY received_at DESC
     LIMIT 30"
) : [];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module whatsapp-module">
    <section class="module-header cost-system-header whatsapp-hero">
        <div>
            <p class="eyebrow">Admin Settings</p>
            <h1>Meta Business Communications</h1>
            <p>Connect Meta Business Manager, configure WhatsApp Cloud API, prepare Instagram/Facebook messaging webhooks, test connection health and monitor events.</p>
        </div>
        <div class="actions">
            <a class="button" href="whatsapp.php"><i data-lucide="arrow-left"></i> Communications hub</a>
        </div>
    </section>

    <?php ops_nav('whatsapp'); ?>
    <?php if (!$ready): ?><section class="ops-alert"><strong>Database setup needed.</strong> Import <code>operations-whatsapp-kpi-migration.sql</code>.</section><?php endif; ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="panel whatsapp-api-strip">
        <div><span>Webhook URL</span><strong><?= htmlspecialchars(wa_webhook_url(), ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>WhatsApp webhook</span><strong><?= htmlspecialchars($settings['last_webhook_received_at'] === 'Never' ? 'Waiting' : $settings['last_webhook_received_at'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Instagram webhook</span><strong><?= htmlspecialchars($settings['last_instagram_webhook_received_at'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Facebook webhook</span><strong><?= htmlspecialchars($settings['last_facebook_webhook_received_at'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>API connection</span><strong><?= htmlspecialchars($settings['last_connection_test_status'], ENT_QUOTES, 'UTF-8') ?></strong></div>
    </section>

    <section class="whatsapp-workspace-grid">
        <article class="panel">
            <div class="section-row"><h2>Meta Business Manager setup</h2><span class="status">Owner/Admin only</span></div>
            <form class="form-grid" method="post">
                <input type="hidden" name="action" value="save_settings">
                <label>Meta Business ID<input name="meta_business_id" value="<?= htmlspecialchars($settings['meta_business_id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Business Manager ID"></label>
                <label>WhatsApp Business Account ID<input name="waba_id" value="<?= htmlspecialchars($settings['waba_id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Meta WABA ID"></label>
                <label>Phone Number ID<input name="phone_number_id" value="<?= htmlspecialchars($settings['phone_number_id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Cloud API phone number ID"></label>
                <label>Facebook Page ID<input name="facebook_page_id" value="<?= htmlspecialchars($settings['facebook_page_id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Page ID for Messenger events"></label>
                <label>Instagram Business Account ID<input name="instagram_business_account_id" value="<?= htmlspecialchars($settings['instagram_business_account_id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Instagram professional account ID"></label>
                <label>Graph API Version<input name="graph_api_version" value="<?= htmlspecialchars($settings['graph_api_version'], ENT_QUOTES, 'UTF-8') ?>" placeholder="v23.0"></label>
                <label>Verify Token<input name="verify_token" value="<?= htmlspecialchars($settings['verify_token'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Choose a private verify token"></label>
                <label>WhatsApp Permanent Access Token<input name="access_token" type="password" value="__KEEP__" autocomplete="new-password"><small>Stored token: <?= htmlspecialchars(wa_mask_secret($settings['access_token']), ENT_QUOTES, 'UTF-8') ?></small></label>
                <label>Page Access Token<input name="page_access_token" type="password" value="__KEEP__" autocomplete="new-password"><small>For Facebook/Instagram webhooks and future replies. Stored token: <?= htmlspecialchars(wa_mask_secret($settings['page_access_token']), ENT_QUOTES, 'UTF-8') ?></small></label>
                <label>Response overdue minutes<input name="response_overdue_minutes" type="number" min="5" value="<?= htmlspecialchars($settings['response_overdue_minutes'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Conversation overdue hours<input name="conversation_overdue_hours" type="number" min="1" value="<?= htmlspecialchars($settings['conversation_overdue_hours'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label class="wide">Complaint keywords<textarea name="complaint_keywords" rows="4"><?= htmlspecialchars($settings['complaint_keywords'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                <button class="button primary" type="submit"><i data-lucide="save"></i> Save settings</button>
            </form>
        </article>

        <article class="panel">
            <div class="section-row"><h2>Connection test</h2><span class="status"><?= htmlspecialchars($settings['last_connection_test_status'], ENT_QUOTES, 'UTF-8') ?></span></div>
            <p>Use this after saving the WhatsApp Phone Number ID and permanent access token. It calls Meta Graph API and records the result. Facebook and Instagram webhook events will be validated by the shared callback URL.</p>
            <form method="post" class="ops-form-actions">
                <input type="hidden" name="action" value="test_connection">
                <button class="button primary" type="submit"><i data-lucide="plug-zap"></i> Test Meta connection</button>
            </form>
            <div class="whatsapp-settings-help">
                <h3>Business Manager webhook setup</h3>
                <p>In Meta App Dashboard, use the callback URL below and the verify token saved on this page.</p>
                <code><?= htmlspecialchars(wa_webhook_url(), ENT_QUOTES, 'UTF-8') ?></code>
                <p>Subscribe to WhatsApp messages/statuses first. When Facebook Page and Instagram messaging permissions are ready, subscribe those messaging events to the same callback URL.</p>
            </div>
        </article>
    </section>

    <section class="work-metric-grid whatsapp-kpi-grid">
        <article class="work-metric-card metric-green"><span class="metric-icon"><i data-lucide="message-circle"></i></span><div><span class="metric-title">WhatsApp</span><strong>Cloud API</strong><small>Ready for real message/status webhooks</small></div></article>
        <article class="work-metric-card metric-pink"><span class="metric-icon"><i data-lucide="instagram"></i></span><div><span class="metric-title">Instagram</span><strong>Prepared</strong><small>Uses Meta messaging webhooks when enabled</small></div></article>
        <article class="work-metric-card metric-blue"><span class="metric-icon"><i data-lucide="messages-square"></i></span><div><span class="metric-title">Facebook</span><strong>Prepared</strong><small>Uses Page/Messenger webhook events</small></div></article>
    </section>

    <section class="panel">
        <div class="section-row"><div><h2>Webhook logs</h2><p>Latest message/status events received from Meta.</p></div></div>
        <div class="table-scroll">
            <table class="data-table ops-table">
                <thead><tr><th>Received</th><th>Platform</th><th>Type</th><th>Account / Phone ID</th><th>Message ID</th><th>Status</th><th>Error</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $log['received_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(ucfirst((string) ($log['platform'] ?? 'whatsapp')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $log['event_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($log['phone_number_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($log['wa_message_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="status"><?= htmlspecialchars((string) $log['processed_status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string) ($log['error_message'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?><tr><td colspan="7">No webhook events received yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
