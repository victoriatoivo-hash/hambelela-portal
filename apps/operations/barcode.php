<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'Barcode Verification | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$result = null;

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $expected = ops_post_string('expected_barcode', 120);
        $scanned = ops_post_string('scanned_barcode', 120);
        $result = $expected !== '' && hash_equals($expected, $scanned) ? 'matched' : 'mismatch';
        $messageText = $result === 'matched' ? 'Barcode matched. Continue packing.' : 'Wrong barcode scanned. Action blocked and logged.';

        $stmt = db()->prepare("INSERT INTO ops_barcode_scans (expected_barcode, scanned_barcode, result, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$expected, $scanned, $result, $messageText]);

        $message = $messageText;
        $messageType = $result === 'matched' ? 'success' : 'error';
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$scans = $ready ? ops_rows("SELECT * FROM ops_barcode_scans ORDER BY scanned_at DESC LIMIT 30") : [];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <section class="module-header"><div><p class="eyebrow">Operations</p><h1>Barcode Verification</h1><p>Barcode scanners work as keyboard input here: click the scan field, scan the product, and the system logs match or mismatch.</p></div></section>
    <?php ops_nav('barcode'); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="ops-split">
        <form class="panel scan-console" method="post">
            <div class="section-row"><h2>Scan product</h2></div>
            <label>Expected barcode<input class="scan-input" name="expected_barcode" autocomplete="off" required></label>
            <label>Scanned barcode<input class="scan-input" name="scanned_barcode" autocomplete="off" autofocus required></label>
            <div class="ops-form-actions"><button class="button primary" type="submit">Verify scan</button></div>
        </form>
        <section class="panel">
            <div class="section-row"><h2>Recent scans</h2></div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Expected</th><th>Scanned</th><th>Result</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($scans as $scan): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $scan['expected_barcode'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($scan['scanned_barcode'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="status"><?= htmlspecialchars($scan['result'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars((string) $scan['scanned_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$scans): ?><tr><td colspan="4">No barcode scans recorded yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
