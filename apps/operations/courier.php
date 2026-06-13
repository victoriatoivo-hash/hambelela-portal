<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'Courier Waybills | ' . APP_NAME;
$activeApp = 'operations-courier';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$currentEmployeeId = ops_current_employee_id();

function courier_try_sql(string $sql): void
{
    try {
        db()->exec($sql);
    } catch (Throwable $e) {
        // Keep the courier screen usable on older installs.
    }
}

function courier_bootstrap_schema(): void
{
    if (!ops_database_ready()) return;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_courier_waybills (
            id INT AUTO_INCREMENT PRIMARY KEY,
            waybill_reference VARCHAR(120) NULL,
            customer_name VARCHAR(190) NULL,
            notes TEXT NULL,
            label_path VARCHAR(255) NOT NULL,
            original_filename VARCHAR(190) NULL,
            uploaded_by INT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_by INT NULL,
            sent_at DATETIME NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'uploaded',
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_waybill_uploaded_at (uploaded_at),
            INDEX idx_waybill_status (status),
            INDEX idx_waybill_uploaded_by (uploaded_by),
            INDEX idx_waybill_sent_by (sent_by)
        )"
    );
    $columns = [
        'waybill_reference' => "ALTER TABLE ops_courier_waybills ADD COLUMN waybill_reference VARCHAR(120) NULL AFTER id",
        'customer_name' => "ALTER TABLE ops_courier_waybills ADD COLUMN customer_name VARCHAR(190) NULL AFTER waybill_reference",
        'notes' => "ALTER TABLE ops_courier_waybills ADD COLUMN notes TEXT NULL AFTER customer_name",
        'original_filename' => "ALTER TABLE ops_courier_waybills ADD COLUMN original_filename VARCHAR(190) NULL AFTER label_path",
        'uploaded_by' => "ALTER TABLE ops_courier_waybills ADD COLUMN uploaded_by INT NULL AFTER original_filename",
        'uploaded_at' => "ALTER TABLE ops_courier_waybills ADD COLUMN uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER uploaded_by",
        'sent_by' => "ALTER TABLE ops_courier_waybills ADD COLUMN sent_by INT NULL AFTER uploaded_at",
        'sent_at' => "ALTER TABLE ops_courier_waybills ADD COLUMN sent_at DATETIME NULL AFTER sent_by",
        'status' => "ALTER TABLE ops_courier_waybills ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'uploaded' AFTER sent_at",
        'updated_at' => "ALTER TABLE ops_courier_waybills ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER status",
    ];
    foreach ($columns as $column => $sql) {
        if (!ops_column_exists('ops_courier_waybills', $column)) courier_try_sql($sql);
    }
}

function courier_upload_label(int $index): ?array
{
    if (empty($_FILES['labels']['name'][$index]) || !is_uploaded_file($_FILES['labels']['tmp_name'][$index])) return null;
    $original = (string) $_FILES['labels']['name'][$index];
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) return null;
    $uploadDir = BASE_PATH . '/uploads/courier-waybills';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $fileName = 'waybill-' . date('YmdHis') . '-' . ($index + 1) . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
    if (!move_uploaded_file($_FILES['labels']['tmp_name'][$index], $uploadDir . '/' . $fileName)) return null;
    return ['path' => 'uploads/courier-waybills/' . $fileName, 'original' => $original];
}

if ($ready) {
    courier_bootstrap_schema();
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
        if ($action === 'upload_labels') {
            if (empty($_FILES['labels']['name']) || !is_array($_FILES['labels']['name'])) {
                throw new RuntimeException('Choose at least one courier label.');
            }
            $created = 0;
            $stmt = db()->prepare(
                "INSERT INTO ops_courier_waybills
                    (waybill_reference, customer_name, notes, label_path, original_filename, uploaded_by, uploaded_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), 'uploaded')"
            );
            foreach ($_FILES['labels']['name'] as $index => $_name) {
                $file = courier_upload_label((int) $index);
                if (!$file) continue;
                $stmt->execute([
                    ops_post_string('waybill_reference', 120) ?: null,
                    ops_post_string('customer_name', 190) ?: null,
                    ops_post_string('notes', 1000) ?: null,
                    $file['path'],
                    $file['original'],
                    $currentEmployeeId,
                ]);
                $created++;
                ops_activity_log('courier_waybill_uploaded', 'courier_waybill', (int) db()->lastInsertId(), ['file' => $file['original']]);
            }
            if ($created <= 0) throw new RuntimeException('No valid labels were uploaded. Use PDF, JPG, PNG or WEBP.');
            notifications_create_for_roles([
                'title' => 'Courier waybill uploaded',
                'message' => $created . ' courier label' . ($created === 1 ? '' : 's') . ' uploaded and ready to send to customers.',
                'module' => 'operations',
                'related_type' => 'courier_waybill',
                'priority' => 'normal',
                'action_link' => BASE_URL . '/apps/operations/courier.php',
            ], ['front_desk_admin', 'owner_admin']);
            $message = $created . ' courier label' . ($created === 1 ? '' : 's') . ' uploaded.';
        } elseif ($action === 'mark_sent') {
            if (!user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager')) {
                throw new RuntimeException('Only front desk or management can mark waybills as sent.');
            }
            $id = (int) ($_POST['waybill_id'] ?? 0);
            $stmt = db()->prepare("UPDATE ops_courier_waybills SET status = 'sent', sent_by = ?, sent_at = NOW() WHERE id = ?");
            $stmt->execute([$currentEmployeeId, $id]);
            ops_activity_log('courier_waybill_sent', 'courier_waybill', $id);
            $message = 'Waybill marked as sent.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$weekStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['week_start'] ?? '')) ? (string) $_GET['week_start'] : date('Y-m-d', strtotime('monday this week'));
$weekEnd = (new DateTimeImmutable($weekStart . ' 00:00:00'))->modify('+7 days')->format('Y-m-d');
$rows = $ready ? ops_rows(
    "SELECT w.*, up.full_name AS uploaded_by_name, sp.full_name AS sent_by_name
     FROM ops_courier_waybills w
     LEFT JOIN ops_employees up ON up.id = w.uploaded_by
     LEFT JOIN ops_employees sp ON sp.id = w.sent_by
     WHERE w.uploaded_at >= ? AND w.uploaded_at < ?
     ORDER BY w.uploaded_at DESC, w.id DESC",
    [$weekStart . ' 00:00:00', $weekEnd . ' 00:00:00']
) : [];
$byDay = [];
foreach ($rows as $row) {
    $day = substr((string) ($row['uploaded_at'] ?? ''), 0, 10);
    $byDay[$day][] = $row;
}
$stats = [
    'uploaded' => count($rows),
    'pending' => count(array_filter($rows, static fn (array $row): bool => (string) ($row['status'] ?? '') !== 'sent')),
    'sent' => count(array_filter($rows, static fn (array $row): bool => (string) ($row['status'] ?? '') === 'sent')),
];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module courier-page">
    <section class="module-header">
        <div>
            <p class="eyebrow">Operations</p>
            <h1>Courier Waybills</h1>
            <p>Upload courier labels, notify front desk, and track when waybills are sent to customers.</p>
        </div>
        <a class="button" href="index.php"><i data-lucide="arrow-left"></i> Operations</a>
    </section>
    <?php ops_nav('courier'); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="work-metric-grid">
        <article class="work-metric-card metric-blue"><span class="metric-title">Uploaded this week</span><strong><?= number_format($stats['uploaded']) ?></strong><small>Courier labels stored</small></article>
        <article class="work-metric-card metric-pink"><span class="metric-title">Pending send</span><strong><?= number_format($stats['pending']) ?></strong><small>Front desk follow-up</small></article>
        <article class="work-metric-card metric-green"><span class="metric-title">Sent to customers</span><strong><?= number_format($stats['sent']) ?></strong><small>Marked as waybill sent</small></article>
    </section>

    <section class="panel courier-upload-panel">
        <div class="section-row"><div><h2>Upload courier labels</h2><p>Upload one or more waybill labels. The system records who uploaded them and alerts front desk.</p></div></div>
        <form class="courier-upload-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_labels">
            <label>Waybill reference<input name="waybill_reference" placeholder="Optional reference"></label>
            <label>Customer name<input name="customer_name" placeholder="Optional customer"></label>
            <label>Labels<input name="labels[]" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple required></label>
            <label class="span-2">Notes<input name="notes" placeholder="Optional note for front desk"></label>
            <button class="button primary" type="submit"><i data-lucide="upload"></i> Upload labels</button>
        </form>
    </section>

    <section class="panel">
        <div class="section-row">
            <div><h2>Weekly waybill board</h2><p>Labels uploaded during the selected week, grouped by upload day.</p></div>
            <form class="courier-week-form" method="get"><label>Week starting<input type="date" name="week_start" value="<?= htmlspecialchars($weekStart, ENT_QUOTES, 'UTF-8') ?>"></label><button class="button" type="submit">View</button></form>
        </div>
        <div class="courier-week-grid">
            <?php for ($day = new DateTimeImmutable($weekStart . ' 00:00:00'); $day < new DateTimeImmutable($weekEnd . ' 00:00:00'); $day = $day->modify('+1 day')): ?>
                <?php $dayKey = $day->format('Y-m-d'); ?>
                <article>
                    <h3><?= htmlspecialchars($day->format('D, M j'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <?php foreach (($byDay[$dayKey] ?? []) as $row): ?>
                        <div class="courier-waybill-card <?= (string) ($row['status'] ?? '') === 'sent' ? 'is-sent' : '' ?>">
                            <div><strong><?= htmlspecialchars((string) ($row['waybill_reference'] ?: $row['original_filename']), ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars((string) ($row['customer_name'] ?: 'Customer not set'), ENT_QUOTES, 'UTF-8') ?></span></div>
                            <p>Uploaded <?= htmlspecialchars(date('H:i', strtotime((string) $row['uploaded_at'])), ENT_QUOTES, 'UTF-8') ?> by <?= htmlspecialchars((string) ($row['uploaded_by_name'] ?: 'Unknown'), ENT_QUOTES, 'UTF-8') ?></p>
                            <?php if ((string) ($row['status'] ?? '') === 'sent'): ?>
                                <p>Sent <?= htmlspecialchars(date('H:i', strtotime((string) $row['sent_at'])), ENT_QUOTES, 'UTF-8') ?> by <?= htmlspecialchars((string) ($row['sent_by_name'] ?: 'Front desk'), ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                            <div class="courier-card-actions">
                                <a class="button" href="<?= htmlspecialchars(BASE_URL . '/' . $row['label_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><i data-lucide="download"></i> Open</a>
                                <?php if ((string) ($row['status'] ?? '') !== 'sent' && user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager')): ?>
                                    <form method="post"><input type="hidden" name="action" value="mark_sent"><input type="hidden" name="waybill_id" value="<?= (int) $row['id'] ?>"><button class="button primary" type="submit"><i data-lucide="send"></i> Waybill sent</button></form>
                                <?php else: ?>
                                    <span class="status"><?= htmlspecialchars((string) ($row['status'] ?? 'uploaded'), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($byDay[$dayKey])): ?><p class="empty-state">No waybills uploaded.</p><?php endif; ?>
                </article>
            <?php endfor; ?>
        </div>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
