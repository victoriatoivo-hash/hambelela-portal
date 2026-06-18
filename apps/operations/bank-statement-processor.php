<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/lib/pdf-extract.php';
require_once BASE_PATH . '/shared/pdf-extractor.php';

require_role('owner_admin', 'front_desk_admin');

$pageTitle = 'Bank Statement Processor | ' . APP_NAME;
$activeApp = 'operations-bank-processor';
$message = null;
$messageType = 'success';
$processorDir = BASE_PATH . '/uploads/bank-processor';
$processorExportDir = $processorDir . '/exports';
$processorTtl = 3600;

if (!isset($_SESSION['bank_statement_processor']) || !is_array($_SESSION['bank_statement_processor'])) {
    $_SESSION['bank_statement_processor'] = [];
}

if (!empty($_SESSION['bank_statement_processor']['created_at']) && (int) $_SESSION['bank_statement_processor']['created_at'] < time() - $processorTtl) {
    $_SESSION['bank_statement_processor'] = [];
}

function bsp_cleanup_uploads(string $dir, int $ttl): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        return;
    }

    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) !== false && filemtime($file) < time() - $ttl) {
            @unlink($file);
        }
    }
}

function bsp_bootstrap_history_table(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS bank_statement_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255),
            upload_date DATE,
            period_label VARCHAR(100),
            transaction_count INT,
            csv_filename VARCHAR(255),
            csv_path VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function bsp_history_rows(): array
{
    try {
        $stmt = db()->query(
            'SELECT id, filename, upload_date, period_label, transaction_count, csv_filename, created_at
             FROM bank_statement_history
             ORDER BY created_at DESC
             LIMIT 10'
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
        if ($stmt) {
            $stmt->closeCursor();
        }

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function bsp_upload_file(string $field, string $dir, array $extensions): array
{
    if (!isset($_FILES[$field]) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return ['ok' => false, 'error' => 'Please upload an FNB Namibia statement PDF.'];
    }

    $file = $_FILES[$field];
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $extensions, true)) {
        return ['ok' => false, 'error' => 'Invalid file type for ' . $file['name'] . '.'];
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo((string) $file['name'], PATHINFO_FILENAME));
    $target = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . $safeName . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'error' => 'The uploaded file could not be saved.'];
    }

    return ['ok' => true, 'path' => $target, 'name' => (string) $file['name']];
}

function bsp_extract_pdf_text(string $path): array
{
    $method = 'none';
    $pdf_path = $path;
    $rawText = '';

    // Load pdfparser from home directory
    $autoloadPath = '/home/hambele1/vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        try {
            $parser  = new \Smalot\PdfParser\Parser();
            $pdf     = $parser->parseFile($pdf_path);
            $rawText = $pdf->getText();
        } catch (Exception $e) {
            $rawText = '';
        }
    }

    if (empty(trim($rawText))) {
        $parseError = 'Could not extract text from PDF.';
    }

    if (trim($rawText) !== '') {
        $method = 'pdfparser';
    }

    if (trim($rawText) === '') {
        $rawText = function_exists('shell_exec')
            ? (string) @shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>&1')
            : '';
        if (strpos($rawText, 'not found') !== false) {
            $rawText = '';
        }
        $method = 'pdftotext';
    }

    if (trim($rawText) === '') {
        $rawText = extractTextFromPDF($path);
        $method = 'pure_php';
    }

    if (trim($rawText) !== '') {
        return ['ok' => true, 'text' => trim($rawText), 'method' => $method];
    }

    return ['ok' => false, 'text' => '', 'method' => $method, 'error' => 'Could not extract text from PDF. The file may be a scanned image. See debug info below.'];
}

function bsp_pdf_debug_info(string $path): array
{
    $autoload = '/home/hambele1/vendor/autoload.php';
    $pdftotextStatus = 'NOT FOUND';
    $rawOutput = '';
    $purePhpOutput = extractTextFromPDF($path);

    if (function_exists('shell_exec')) {
        $which = shell_exec('which pdftotext 2>&1');
        $version = shell_exec('pdftotext -v 2>&1');
        $pdftotextStatus = trim((string) ($which ?: $version));
        if ($pdftotextStatus === '') {
            $pdftotextStatus = 'NOT FOUND';
        }
        $rawOutput = (string) shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>&1');
    }

    return [
        'php_version' => phpversion(),
        'pdfparser_available' => is_file($autoload) ? 'YES' : 'NO - composer not run',
        'pdftotext_available' => $pdftotextStatus,
        'raw_pdftotext_output' => substr($rawOutput, 0, 3000),
        'pure_php_output' => substr($purePhpOutput, 0, 500),
        'pdf_file_size' => is_file($path) ? filesize($path) . ' bytes' : 'File not found',
        'pdf_mime_type' => function_exists('mime_content_type') && is_file($path) ? (string) mime_content_type($path) : 'Unknown',
    ];
}

function bsp_amount_to_float(?string $value): ?float
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '' || $value === '-') {
        return null;
    }

    $negative = strpos($value, '(') !== false || strpos($value, '-') === 0;
    $clean = preg_replace('/[^0-9.]/', '', str_replace(',', '', $value));
    if ($clean === '' || $clean === null) {
        return null;
    }

    $amount = (float) $clean;
    return $negative ? -1 * $amount : $amount;
}

function bsp_parse_date(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('d M Y', trim($date));
    return $parsed ? $parsed->format('d/m/Y') : trim($date);
}

function bsp_format_amount(?float $amount, bool $signed = false): string
{
    if ($amount === null || abs($amount) < 0.005) {
        return '';
    }

    return number_format($signed ? $amount : abs($amount), 2, '.', '');
}

function bsp_is_ignored_line(string $line): bool
{
    return preg_match('/\b(date|description|reference|debit|credit|balance|opening balance|closing balance|balance brought forward|brought forward|carried forward|account summary|account number|page \d+|first national bank)\b/i', $line) === 1;
}

function bsp_parse_fnb_transactions(string $text): array
{
    $transactions = [];
    $rawText = $text;
    $lines = explode("\n", $rawText);

    foreach ($lines as $line) {
        $line = trim($line);

        // Must start with DD Mon pattern
        if (!preg_match('/^(\d{2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(.+)/i', $line, $m)) {
            continue;
        }

        $day   = $m[1];
        $month = $m[2];
        $rest  = trim($m[3]);

        // Extract all numbers from the rest of the line
        // Numbers look like: 442.00 or 14,500.00 or 442.00Cr
        preg_match_all('/([\d,]+\.\d{2})(Cr)?/i', $rest, $nums, PREG_SET_ORDER);

        if (count($nums) < 2) continue; // need at least amount + balance

        // Last match = balance, second to last = amount
        $balanceMatch = array_pop($nums);
        $amountMatch  = array_pop($nums);

        $balance = str_replace(',', '', $balanceMatch[1]);
        $amount  = str_replace(',', '', $amountMatch[1]);
        $isCr    = !empty($amountMatch[2]); // has "Cr" suffix

        // Remove all numbers and "Cr" from rest to get description
        $description = preg_replace('/([\d,]+\.\d{2})(Cr)?/i', '', $rest);
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim($description);

        // Build date - year is current year, but handle Jan in
        // December context: if month is Jan-Mar use 2025, else 2025
        // Use the year from the statement header if possible,
        // otherwise default to 2025
        $year = 2025; // will be overridden below

        // Try to extract year from statement header text
        if (preg_match('/20\d{2}/', $rawText, $yearMatch)) {
            $year = $yearMatch[0];
        }

        $dateStr  = $day . ' ' . $month . ' ' . $year;
        $dateObj  = DateTime::createFromFormat('d M Y', $dateStr);
        $date     = $dateObj ? $dateObj->format('d/m/Y') : $day . '/' . $month . '/' . $year;

        $debit  = $isCr ? '' : number_format((float)$amount, 2, '.', '');
        $credit = $isCr ? number_format((float)$amount, 2, '.', '') : '';

        // Skip opening/closing balance lines
        if (stripos($description, 'opening balance') !== false) continue;
        if (stripos($description, 'closing balance') !== false) continue;
        if (stripos($description, 'balance brought') !== false) continue;

        $transactions[] = [
            'date'        => $date,
            'description' => $description,
            'reference'   => '',
            'debit'       => $debit,
            'credit'      => $credit,
            'balance'     => (float) $balance,
        ];
    }

    return $transactions;
}

function bsp_sage_headers(): array
{
    return ['Date', 'Description', 'Reference', 'Debit', 'Credit'];
}

function bsp_sage_rows(array $transactions): array
{
    $rows = [];
    foreach ($transactions as $transaction) {
        $rows[] = [
            (string) ($transaction['date'] ?? ''),
            (string) ($transaction['description'] ?? ''),
            (string) ($transaction['reference'] ?? ''),
            (string) ($transaction['debit'] ?? ''),
            (string) ($transaction['credit'] ?? ''),
        ];
    }

    return $rows;
}

function bsp_csv_download(array $transactions, ?string $filename = null): void
{
    $filename = $filename ?: 'sage_import_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, bsp_sage_headers());
    foreach (bsp_sage_rows($transactions) as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function bsp_write_csv(string $path, array $transactions): void
{
    $out = fopen($path, 'wb');
    if (!$out) {
        throw new RuntimeException('The export CSV could not be saved on the server.');
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, bsp_sage_headers());
    foreach (bsp_sage_rows($transactions) as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
}

function bsp_statement_summary(array $transactions): array
{
    $dates = [];
    $totalDebits = 0.0;
    $totalCredits = 0.0;
    foreach ($transactions as $transaction) {
        $date = DateTimeImmutable::createFromFormat('d/m/Y', (string) ($transaction['date'] ?? ''));
        if ($date instanceof DateTimeImmutable) {
            $dates[] = $date;
        }
        $totalDebits += (float) ($transaction['debit'] ?? 0);
        $totalCredits += (float) ($transaction['credit'] ?? 0);
    }

    usort($dates, static function (DateTimeImmutable $a, DateTimeImmutable $b): int {
        return $a->getTimestamp() <=> $b->getTimestamp();
    });

    $period = $dates
        ? $dates[0]->format('d/m/Y') . ' - ' . $dates[count($dates) - 1]->format('d/m/Y')
        : '-';

    return [
        'count' => count($transactions),
        'period' => $period,
        'total_debits' => $totalDebits,
        'total_credits' => $totalCredits,
    ];
}

function bsp_period_label(array $transactions): string
{
    $summary = bsp_statement_summary($transactions);
    return $summary['period'] === '-' ? '' : (string) $summary['period'];
}

function bsp_history_insert(string $filename, string $periodLabel, int $transactionCount, string $csvFilename, string $csvPath): void
{
    $stmt = db()->prepare(
        'INSERT INTO bank_statement_history (filename, upload_date, period_label, transaction_count, csv_filename, csv_path)
         VALUES (?, CURDATE(), ?, ?, ?, ?)'
    );
    $stmt->execute([$filename, $periodLabel, $transactionCount, $csvFilename, $csvPath]);
}

function bsp_download_history_csv(int $id, string $exportDir): void
{
    $stmt = db()->prepare('SELECT csv_filename, csv_path FROM bank_statement_history WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $stmt->closeCursor();
    if (!$row) {
        http_response_code(404);
        exit('CSV export not found.');
    }

    $path = (string) ($row['csv_path'] ?? '');
    $realPath = realpath($path);
    $realExportDir = realpath($exportDir);
    if (!$realPath || !$realExportDir || strpos($realPath, $realExportDir) !== 0 || !is_file($realPath)) {
        http_response_code(404);
        exit('CSV export file is no longer available.');
    }

    $filename = basename((string) ($row['csv_filename'] ?: 'statement-export.csv'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . filesize($realPath));
    readfile($realPath);
    exit;
}

bsp_cleanup_uploads($processorDir, $processorTtl);
if (!is_dir($processorExportDir)) {
    mkdir($processorExportDir, 0755, true);
}
bsp_bootstrap_history_table();

if (isset($_GET['download'])) {
    bsp_download_history_csv(max(0, (int) $_GET['download']), $processorExportDir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'process_upload') {
            $pdf = bsp_upload_file('bank_statement_pdf', $processorDir, ['pdf']);
            if (!($pdf['ok'] ?? false)) {
                throw new RuntimeException((string) $pdf['error']);
            }

            $textResult = bsp_extract_pdf_text((string) $pdf['path']);
            if (!($textResult['ok'] ?? false)) {
                $_SESSION['bank_statement_processor'] = [
                    'pdf_name' => $pdf['name'],
                    'transactions' => [],
                    'raw_text' => (string) ($textResult['text'] ?? ''),
                    'method' => (string) ($textResult['method'] ?? 'none'),
                    'debug_info' => bsp_pdf_debug_info((string) $pdf['path']),
                    'created_at' => time(),
                ];
                throw new RuntimeException((string) ($textResult['error'] ?? 'Could not extract any text from this PDF. See debug info below.'));
            }

            $transactions = bsp_parse_fnb_transactions((string) $textResult['text']);
            $_SESSION['bank_statement_processor'] = [
                'pdf_name' => $pdf['name'],
                'transactions' => $transactions,
                'raw_text' => (string) $textResult['text'],
                'method' => (string) $textResult['method'],
                'debug_info' => $transactions ? [] : bsp_pdf_debug_info((string) $pdf['path']),
                'created_at' => time(),
            ];

            if (!$transactions) {
                $message = 'No transactions found. See raw text below.';
                $messageType = 'error';
            } else {
                $message = number_format(count($transactions)) . ' transactions found. Review the preview and download the Sage CSV.';
            }
        } elseif ($action === 'export_csv') {
            $state = $_SESSION['bank_statement_processor'];
            if (empty($state['transactions'])) {
                throw new RuntimeException('Upload and parse a statement before exporting.');
            }

            $csvFilename = 'fnb_sage_' . date('Y-m-d_His') . '.csv';
            $csvPath = $processorExportDir . '/' . $csvFilename;
            bsp_write_csv($csvPath, $state['transactions']);
            bsp_history_insert(
                (string) ($state['pdf_name'] ?? 'FNB statement.pdf'),
                bsp_period_label($state['transactions']),
                count($state['transactions']),
                $csvFilename,
                $csvPath
            );
            bsp_csv_download($state['transactions'], $csvFilename);
        } elseif ($action === 'clear_processor') {
            $_SESSION['bank_statement_processor'] = [];
            $message = 'Processor reset. Upload the files again to re-parse.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$state = $_SESSION['bank_statement_processor'];
$transactions = is_array($state['transactions'] ?? null) ? $state['transactions'] : [];
$summary = bsp_statement_summary($transactions);
$historyRows = bsp_history_rows();

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module bank-processor-page">
    <section class="module-header cost-system-header">
        <div>
            <p class="eyebrow">Sage Accounting Import</p>
            <h1>Bank Statement Processor</h1>
            <p>Upload an FNB Namibia PDF statement. The processor extracts transactions and builds a Sage Accounting South Africa bank statement CSV.</p>
        </div>
        <div class="actions">
            <a class="button" href="index.php"><i data-lucide="arrow-left"></i> Operations</a>
            <?php if ($transactions): ?>
                <form method="post">
                    <input type="hidden" name="action" value="clear_processor">
                    <button class="button" type="submit"><i data-lucide="refresh-cw"></i> Re-parse</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <?php ops_nav('bank-processor'); ?>
    <?php if ($message): ?>
        <section class="ops-alert <?= $messageType === 'error' ? 'bank-error-alert' : '' ?>"><strong><?= $messageType === 'error' ? 'Could not process.' : 'Ready.' ?></strong> <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></section>
    <?php endif; ?>

    <section class="panel bank-upload-panel">
        <div class="section-row">
            <div>
                <p class="bank-section-label">Step 1 of 3</p>
                <h2>Upload PDF</h2>
                <p>Temporary uploads are stored in <code>uploads/bank-processor</code> and cleared after one hour.</p>
            </div>
            <span class="bank-status-badge <?= $transactions ? 'complete' : 'progress' ?>"><?= $transactions ? number_format(count($transactions)) . ' rows detected' : 'Waiting for files' ?></span>
        </div>
        <form class="ops-form bank-upload-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="process_upload">
            <input id="pdf_input" type="file" name="bank_statement_pdf" accept="application/pdf,.pdf" hidden>
            <div class="bank-single-upload-wrap">
                <div class="bank-file-zone" data-upload-zone data-input-id="pdf_input" onclick="document.getElementById('pdf_input').click()" ondragover="bankDragOver(event)" ondragleave="bankDragLeave(event)" ondrop="bankDrop(event, 'pdf_input')">
                    <span data-upload-icon><i data-lucide="file-text"></i></span>
                    <strong>FNB Namibia Statement PDF</strong>
                    <small>Upload your FNB business or current account statement</small>
                    <em data-upload-filename><?= htmlspecialchars((string) ($state['pdf_name'] ?? 'No file selected'), ENT_QUOTES, 'UTF-8') ?></em>
                </div>
            </div>
            <div class="bank-upload-progress" data-bank-upload-progress hidden>
                <span></span>
            </div>
            <div class="ops-form-actions">
                <button class="button primary bank-submit-btn bank-upload-submit" type="submit" data-bank-submit>
                    <i data-lucide="upload-cloud" data-submit-icon></i>
                    <span data-submit-label>Upload and parse</span>
                </button>
            </div>
        </form>
    </section>

    <?php if ($transactions): ?>
        <section class="panel bank-mapping-panel">
            <div class="section-row">
                <div>
                    <p class="bank-section-label">Step 2 of 3</p>
                    <h2>Preview Sage export</h2>
                    <p>All parsed transactions in the fixed Sage Accounting South Africa bank statement import format.</p>
                </div>
                <span class="bank-status-badge complete"><?= htmlspecialchars((string) ($state['method'] ?? 'parsed'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="bank-summary-bar">
                <span><?= number_format((int) $summary['count']) ?> transactions found</span>
                <span>Period: <?= htmlspecialchars((string) $summary['period'], ENT_QUOTES, 'UTF-8') ?></span>
                <span>Total debits: N$ <?= number_format((float) $summary['total_debits'], 2) ?></span>
                <span>Total credits: N$ <?= number_format((float) $summary['total_credits'], 2) ?></span>
            </div>
            <div class="table-scroll bank-preview-scroll">
                <table class="data-table bank-preview-table">
                    <thead>
                        <tr>
                            <?php foreach (bsp_sage_headers() as $header): ?>
                                <th><?= htmlspecialchars($header, ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (bsp_sage_rows($transactions) as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?= htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" class="bank-download-form">
                <input type="hidden" name="action" value="export_csv">
                <button class="button primary bank-download-sage-btn" type="submit"><i data-lucide="download"></i> Download CSV for Sage</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if (!$transactions && (!empty($state['raw_text']) || !empty($state['debug_info']))): ?>
        <section class="panel bank-raw-panel">
            <div class="section-row">
                <div>
                    <h2>No transactions found. See raw text below.</h2>
                </div>
            </div>
            <details>
                <summary>Raw extracted PDF text</summary>
                <pre class="text-preview"><?= htmlspecialchars(substr((string) ($state['raw_text'] ?? ''), 0, 12000), ENT_QUOTES, 'UTF-8') ?></pre>
            </details>
            <?php if (!empty($state['debug_info']) && is_array($state['debug_info'])): ?>
                <details class="bank-debug-info">
                    <summary>Debug Info</summary>
                    <dl>
                        <dt>PHP version</dt>
                        <dd><?= htmlspecialchars((string) ($state['debug_info']['php_version'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt>pdfparser available</dt>
                        <dd><?= htmlspecialchars((string) ($state['debug_info']['pdfparser_available'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt>pdftotext available</dt>
                        <dd><pre><?= htmlspecialchars((string) ($state['debug_info']['pdftotext_available'] ?? 'NOT FOUND'), ENT_QUOTES, 'UTF-8') ?></pre></dd>
                        <dt>PDF file size</dt>
                        <dd><?= htmlspecialchars((string) ($state['debug_info']['pdf_file_size'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt>PDF file mime type</dt>
                        <dd><?= htmlspecialchars((string) ($state['debug_info']['pdf_mime_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt>Raw pdftotext output (first 3000 characters)</dt>
                        <dd><pre><?= htmlspecialchars((string) ($state['debug_info']['raw_pdftotext_output'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></dd>
                        <dt>Pure PHP extractor</dt>
                        <dd><pre><?= htmlspecialchars((string) ($state['debug_info']['pure_php_output'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></dd>
                    </dl>
                </details>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel bank-history-panel">
        <div class="section-row">
            <div>
                <p class="bank-section-label">Step 3 of 3</p>
                <h2>Past Statements</h2>
                <p>Recent processed FNB statements and saved Sage CSV exports.</p>
            </div>
            <span class="bank-status-badge progress"><?= number_format(count($historyRows)) ?> shown</span>
        </div>
        <div class="bank-history-list">
            <?php foreach ($historyRows as $history): ?>
                <div class="bank-history-row">
                    <div>
                        <strong><?= htmlspecialchars((string) ($history['filename'] ?: 'FNB statement.pdf'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <small><?= htmlspecialchars((string) ($history['upload_date'] ?: substr((string) $history['created_at'], 0, 10)), ENT_QUOTES, 'UTF-8') ?><?= !empty($history['period_label']) ? ' - ' . htmlspecialchars((string) $history['period_label'], ENT_QUOTES, 'UTF-8') : '' ?></small>
                    </div>
                    <div class="bank-history-actions">
                        <span><?= number_format((int) $history['transaction_count']) ?> transactions</span>
                        <a class="bank-download-btn" href="?download=<?= (int) $history['id'] ?>" aria-label="Download saved CSV for <?= htmlspecialchars((string) ($history['filename'] ?: 'statement'), ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="download"></i></a>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$historyRows): ?>
                <p class="bank-empty-state">No statements processed yet</p>
            <?php endif; ?>
        </div>
    </section>

</main>
<script>
function bankSetZoneFile(input) {
  if (!input || !input.files || !input.files.length) return;
  var zone = document.querySelector('[data-input-id="' + input.id + '"]');
  if (!zone) return;
  var icon = zone.querySelector('[data-upload-icon]');
  var filename = zone.querySelector('[data-upload-filename]');
  zone.classList.add('has-file');
  zone.classList.remove('is-dragging');
  if (icon) icon.innerHTML = '<i data-lucide="check"></i>';
  if (filename) filename.textContent = input.files[0].name;
  if (window.lucide) window.lucide.createIcons();
}

function bankDragOver(event) {
  event.preventDefault();
  event.currentTarget.classList.add('is-dragging');
}

function bankDragLeave(event) {
  event.preventDefault();
  event.currentTarget.classList.remove('is-dragging');
}

function bankDrop(event, inputId) {
  event.preventDefault();
  var zone = event.currentTarget;
  zone.classList.remove('is-dragging');
  var input = document.getElementById(inputId);
  if (!input || !event.dataTransfer || !event.dataTransfer.files.length) return;
  input.files = event.dataTransfer.files;
  bankSetZoneFile(input);
}

['pdf_input'].forEach(function (id) {
  var input = document.getElementById(id);
  if (input) input.addEventListener('change', function () { bankSetZoneFile(input); });
});

var uploadForm = document.querySelector('.bank-upload-form');
if (uploadForm) {
  uploadForm.addEventListener('submit', function () {
    var progress = document.querySelector('[data-bank-upload-progress]');
    var button = document.querySelector('[data-bank-submit]');
    var icon = button ? button.querySelector('[data-submit-icon]') : null;
    var label = button ? button.querySelector('[data-submit-label]') : null;
    if (progress) {
      progress.hidden = false;
      requestAnimationFrame(function () { progress.classList.add('active'); });
    }
    if (button) {
      button.disabled = true;
      button.classList.add('is-processing');
    }
    if (icon) icon.setAttribute('data-lucide', 'loader-circle');
    if (label) label.textContent = 'Processing...';
    if (window.lucide) window.lucide.createIcons();
  });
}
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
