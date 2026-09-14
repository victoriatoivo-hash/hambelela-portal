<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/pdf-extractor.php';

require_role('owner_admin', 'front_desk_admin');

$pageTitle = 'Bank Statement Processor | ' . APP_NAME;
$activeApp = 'operations-bank-processor';
$message = null;
$messageType = 'success';
$processorDir = BASE_PATH . '/uploads/bank-processor';
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

function bsp_upload_file(string $field, string $dir, array $extensions): array
{
    if (!isset($_FILES[$field]) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return ['ok' => false, 'error' => 'Please upload both the FNB statement PDF and Sage CSV template.'];
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
    $autoload = BASE_PATH . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $text = trim($parser->parseFile($path)->getText());
                if ($text !== '') {
                    return ['ok' => true, 'text' => $text, 'method' => 'pdfparser'];
                }
            } catch (Throwable $e) {
                // Fall through to pdftotext below.
            }
        }
    }

    $result = extract_pdf_text($path);
    if (($result['available'] ?? false) && trim((string) ($result['text'] ?? '')) !== '') {
        return ['ok' => true, 'text' => (string) $result['text'], 'method' => 'pdftotext'];
    }

    return ['ok' => false, 'text' => '', 'method' => 'none', 'error' => $result['message'] ?? 'Could not parse PDF - is this an FNB Namibia statement?'];
}

function bsp_read_csv_headers(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return [];
    }

    $headers = fgetcsv($handle);
    fclose($handle);
    if (!is_array($headers)) {
        return [];
    }

    $headers = array_map(static function ($value): string {
        return trim((string) $value);
    }, $headers);
    $headers = array_values(array_filter($headers, static function (string $value): bool {
        return $value !== '';
    }));

    return $headers;
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
    return preg_match('/\b(date|description|reference|debit|credit|balance|opening balance|closing balance|brought forward|carried forward|account summary|page \d+|first national bank)\b/i', $line) === 1;
}

function bsp_parse_fnb_transactions(string $text): array
{
    $rows = [];
    $pending = '';
    $lines = preg_split('/\R+/', $text) ?: [];

    foreach ($lines as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');
        if ($line === '' || bsp_is_ignored_line($line)) {
            continue;
        }

        if (preg_match('/^\d{1,2}\s+[A-Za-z]{3}\s+\d{4}\b/', $line)) {
            if ($pending !== '') {
                $rows[] = $pending;
            }
            $pending = $line;
            continue;
        }

        if ($pending !== '') {
            $pending .= ' ' . $line;
        }
    }

    if ($pending !== '') {
        $rows[] = $pending;
    }

    $parsedRows = [];
    foreach ($rows as $row) {
        if (!preg_match('/^(\d{1,2}\s+[A-Za-z]{3}\s+\d{4})\s+(.+)$/', $row, $matches)) {
            continue;
        }

        $date = $matches[1];
        $rest = trim($matches[2]);
        preg_match_all('/-?\(?\d{1,3}(?:,\d{3})*(?:\.\d{2})\)?|-?\(?\d+\.\d{2}\)?/', $rest, $amountMatches, PREG_OFFSET_CAPTURE);
        $amounts = $amountMatches[0] ?? [];
        if (count($amounts) < 2) {
            continue;
        }

        $lastAmounts = array_slice($amounts, -3);
        $firstAmountOffset = (int) $lastAmounts[0][1];
        $description = trim(substr($rest, 0, $firstAmountOffset));
        if ($description === '') {
            continue;
        }

        $numeric = array_map(static function (array $match): ?float {
            return bsp_amount_to_float((string) $match[0]);
        }, $lastAmounts);
        if (count($numeric) === 2) {
            [$transactionAmount, $balance] = $numeric;
            $debit = null;
            $credit = null;
        } else {
            [$debitRaw, $creditRaw, $balance] = $numeric;
            $transactionAmount = null;
            $debit = $debitRaw !== null && abs($debitRaw) > 0.005 ? abs($debitRaw) : null;
            $credit = $creditRaw !== null && abs($creditRaw) > 0.005 ? abs($creditRaw) : null;
        }

        $parsedRows[] = [
            'date' => bsp_parse_date($date),
            'description' => $description,
            'transaction_amount' => $transactionAmount,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
        ];
    }

    $transactions = [];
    $previousBalance = null;
    foreach ($parsedRows as $row) {
        $debit = $row['debit'];
        $credit = $row['credit'];
        $balance = $row['balance'];
        $transactionAmount = $row['transaction_amount'];

        if ($transactionAmount !== null && $debit === null && $credit === null) {
            $amount = abs($transactionAmount);
            if ($transactionAmount < 0) {
                $debit = $amount;
            } elseif ($previousBalance !== null && $balance !== null) {
                $movement = $balance - $previousBalance;
                if ($movement < -0.005) {
                    $debit = $amount;
                } elseif ($movement > 0.005) {
                    $credit = $amount;
                }
            }

            if ($debit === null && $credit === null) {
                $debitHints = '/\b(fee|fees|charge|charges|payment|purchase|debit order|service fee|account fee|atm|transfer to)\b/i';
                if (preg_match($debitHints, (string) $row['description']) === 1) {
                    $debit = $amount;
                } else {
                    $credit = $amount;
                }
            }
        }

        $row['amount'] = $credit !== null ? $credit : ($debit !== null ? -1 * $debit : null);
        unset($row['transaction_amount']);
        $row['debit'] = $debit;
        $row['credit'] = $credit;
        $transactions[] = $row;

        if ($balance !== null) {
            $previousBalance = $balance;
        }
    }

    return $transactions;
}

function bsp_default_mapping(array $headers): array
{
    $mapping = [
        'date' => '',
        'description' => '',
        'debit' => '',
        'credit' => '',
        'amount' => '',
        'balance' => '',
    ];

    foreach ($headers as $header) {
        $key = strtolower(preg_replace('/[^a-z0-9]+/', '', $header) ?? '');
        if ($key === 'date') {
            $mapping['date'] = $header;
        } elseif (in_array($key, ['description', 'reference', 'details', 'narration'], true)) {
            $mapping['description'] = $header;
        } elseif ($key === 'debit') {
            $mapping['debit'] = $header;
        } elseif ($key === 'credit') {
            $mapping['credit'] = $header;
        } elseif (in_array($key, ['amount', 'transactionamount'], true)) {
            $mapping['amount'] = $header;
        } elseif ($key === 'balance') {
            $mapping['balance'] = $header;
        }
    }

    return $mapping;
}

function bsp_source_value(array $row, string $source): string
{
    switch ($source) {
        case 'date':
            return (string) $row['date'];
        case 'description':
            return (string) $row['description'];
        case 'debit':
            return bsp_format_amount($row['debit']);
        case 'credit':
            return bsp_format_amount($row['credit']);
        case 'amount':
            return bsp_format_amount($row['amount'], true);
        case 'balance':
            return bsp_format_amount($row['balance'], true);
        default:
            return '';
    }
}

function bsp_mapped_rows(array $headers, array $mapping, array $transactions): array
{
    $rows = [];
    foreach ($transactions as $transaction) {
        $output = [];
        foreach ($headers as $header) {
            $source = array_search($header, $mapping, true);
            $output[] = is_string($source) ? bsp_source_value($transaction, $source) : '';
        }
        $rows[] = $output;
    }

    return $rows;
}

function bsp_csv_download(array $headers, array $mapping, array $transactions): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sage_import_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach (bsp_mapped_rows($headers, $mapping, $transactions) as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

bsp_cleanup_uploads($processorDir, $processorTtl);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'process_upload') {
            $pdf = bsp_upload_file('bank_statement_pdf', $processorDir, ['pdf']);
            if (!($pdf['ok'] ?? false)) {
                throw new RuntimeException((string) $pdf['error']);
            }

            $csv = bsp_upload_file('sage_template_csv', $processorDir, ['csv']);
            if (!($csv['ok'] ?? false)) {
                throw new RuntimeException((string) $csv['error']);
            }

            $headers = bsp_read_csv_headers((string) $csv['path']);
            if (!$headers) {
                throw new RuntimeException('Sage template has no recognisable headers. The first row must contain column headings.');
            }

            $textResult = bsp_extract_pdf_text((string) $pdf['path']);
            if (!($textResult['ok'] ?? false)) {
                throw new RuntimeException((string) ($textResult['error'] ?? 'Could not parse PDF - is this an FNB Namibia statement?'));
            }

            $transactions = bsp_parse_fnb_transactions((string) $textResult['text']);
            $_SESSION['bank_statement_processor'] = [
                'pdf_name' => $pdf['name'],
                'csv_name' => $csv['name'],
                'headers' => $headers,
                'transactions' => $transactions,
                'raw_text' => (string) $textResult['text'],
                'method' => (string) $textResult['method'],
                'mapping' => bsp_default_mapping($headers),
                'created_at' => time(),
            ];

            if (!$transactions) {
                $message = 'Zero transactions extracted. The raw extracted text is shown below for debugging.';
                $messageType = 'error';
            } else {
                $message = number_format(count($transactions)) . ' transactions detected. Review the Sage column mapping before export.';
            }
        } elseif ($action === 'update_mapping' || $action === 'export_csv') {
            $state = $_SESSION['bank_statement_processor'];
            if (empty($state['headers']) || empty($state['transactions'])) {
                throw new RuntimeException('Upload and parse a statement before exporting.');
            }

            $headers = array_map('strval', $state['headers']);
            $mapping = [];
            foreach (['date', 'description', 'debit', 'credit', 'amount', 'balance'] as $source) {
                $value = trim((string) ($_POST['mapping'][$source] ?? ''));
                $mapping[$source] = in_array($value, $headers, true) ? $value : '';
            }
            $_SESSION['bank_statement_processor']['mapping'] = $mapping;

            if ($action === 'export_csv') {
                bsp_csv_download($headers, $mapping, $state['transactions']);
            }

            $message = 'Mapping updated.';
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
$headers = array_map('strval', $state['headers'] ?? []);
$transactions = is_array($state['transactions'] ?? null) ? $state['transactions'] : [];
$mapping = is_array($state['mapping'] ?? null) ? $state['mapping'] : bsp_default_mapping($headers);
$previewRows = $headers && $transactions ? array_slice(bsp_mapped_rows($headers, $mapping, $transactions), 0, 10) : [];
$sourceLabels = [
    'date' => 'Date',
    'description' => 'Description / Reference',
    'debit' => 'Debit',
    'credit' => 'Credit',
    'amount' => 'Amount',
    'balance' => 'Balance',
];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module bank-processor-page">
    <section class="module-header cost-system-header">
        <div>
            <p class="eyebrow">Sage Accounting Import</p>
            <h1>Bank Statement Processor</h1>
            <p>Upload an FNB Namibia PDF statement and a Sage Accounting CSV template. The processor reads the template headers, extracts statement transactions, and builds a clean import CSV.</p>
        </div>
        <div class="actions">
            <a class="button" href="index.php"><i data-lucide="arrow-left"></i> Operations</a>
            <?php if ($headers || $transactions): ?>
                <form method="post">
                    <input type="hidden" name="action" value="clear_processor">
                    <button class="button" type="submit"><i data-lucide="refresh-cw"></i> Re-parse</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <?php ops_nav('bank-processor'); ?>
    <?php if ($message): ?>
        <section class="ops-alert"><strong><?= $messageType === 'error' ? 'Could not process.' : 'Ready.' ?></strong> <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></section>
    <?php endif; ?>

    <section class="panel bank-upload-panel">
        <div class="section-row">
            <div>
                <h2>Upload files</h2>
                <p>Temporary uploads are stored in <code>uploads/bank-processor</code> and cleared after one hour.</p>
            </div>
            <span class="status"><?= $transactions ? number_format(count($transactions)) . ' rows detected' : 'Waiting for files' ?></span>
        </div>
        <form class="ops-form bank-upload-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="process_upload">
            <div class="form-grid">
                <label class="bank-file-zone">
                    <input type="file" name="bank_statement_pdf" accept="application/pdf,.pdf" required>
                    <span><i data-lucide="file-text"></i></span>
                    <strong>FNB Namibia statement PDF</strong>
                    <small><?= htmlspecialchars((string) ($state['pdf_name'] ?? 'PDF statement file'), ENT_QUOTES, 'UTF-8') ?></small>
                </label>
                <label class="bank-file-zone">
                    <input type="file" name="sage_template_csv" accept=".csv,text/csv" required>
                    <span><i data-lucide="table"></i></span>
                    <strong>Sage import template CSV</strong>
                    <small><?= htmlspecialchars((string) ($state['csv_name'] ?? 'CSV with Sage headers in row 1'), ENT_QUOTES, 'UTF-8') ?></small>
                </label>
            </div>
            <div class="bank-upload-progress" data-bank-upload-progress hidden>
                <span></span>
            </div>
            <div class="ops-form-actions">
                <button class="button primary" type="submit"><i data-lucide="upload-cloud"></i> Upload and parse</button>
            </div>
        </form>
    </section>

    <?php if ($headers): ?>
        <form class="panel bank-mapping-panel" method="post">
            <input type="hidden" name="action" value="update_mapping">
            <div class="section-row">
                <div>
                    <h2>Column mapping</h2>
                    <p>Choose which Sage template column receives each FNB value. Leave a row unmapped if the Sage template does not need it.</p>
                </div>
                <span class="status success"><?= htmlspecialchars((string) ($state['method'] ?? 'parsed'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="table-scroll">
                <table class="data-table bank-mapping-table">
                    <thead><tr><th>FNB column</th><th>Sage column</th></tr></thead>
                    <tbody>
                        <?php foreach ($sourceLabels as $source => $label): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td>
                                    <select name="mapping[<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>]">
                                        <option value="">Do not export</option>
                                        <?php foreach ($headers as $header): ?>
                                            <option value="<?= htmlspecialchars($header, ENT_QUOTES, 'UTF-8') ?>" <?= ($mapping[$source] ?? '') === $header ? 'selected' : '' ?>><?= htmlspecialchars($header, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="ops-form-actions">
                <button class="button" type="submit"><i data-lucide="check"></i> Update preview</button>
                <?php if ($transactions): ?>
                    <button class="button primary" type="submit" name="action" value="export_csv"><i data-lucide="download"></i> Export Sage CSV</button>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($headers && $transactions): ?>
        <section class="panel bank-preview-panel">
            <div class="section-row">
                <div>
                    <h2>Preview</h2>
                    <p>First 10 rows in the mapped Sage format. Total rows detected: <strong><?= number_format(count($transactions)) ?></strong>.</p>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="clear_processor">
                    <button class="button small" type="submit"><i data-lucide="refresh-cw"></i> Looks wrong? Re-parse</button>
                </form>
            </div>
            <div class="table-scroll">
                <table class="data-table bank-preview-table">
                    <thead>
                        <tr>
                            <?php foreach ($headers as $header): ?>
                                <th><?= htmlspecialchars($header, ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?= htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($headers && !$transactions): ?>
        <section class="panel">
            <div class="section-row">
                <div>
                    <h2>Raw extracted text</h2>
                    <p>Use this to check whether the PDF text layout matches the FNB Namibia transaction format.</p>
                </div>
            </div>
            <pre class="text-preview"><?= htmlspecialchars(substr((string) ($state['raw_text'] ?? ''), 0, 12000), ENT_QUOTES, 'UTF-8') ?></pre>
        </section>
    <?php endif; ?>
</main>
<script>
document.querySelector('.bank-upload-form')?.addEventListener('submit', () => {
  const progress = document.querySelector('[data-bank-upload-progress]');
  if (!progress) return;
  progress.hidden = false;
  requestAnimationFrame(() => progress.classList.add('active'));
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
