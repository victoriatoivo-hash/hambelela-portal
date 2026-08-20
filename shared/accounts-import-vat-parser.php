<?php
declare(strict_types=1);

/**
 * Convert a NamRA VAT Import Account tax year/period to its accounting month.
 * NamRA period 1 is April of the preceding calendar year; period 12 is March.
 */
function import_vat_tax_period_month(string $taxYear, string $taxPeriod): ?string
{
    $year = (int)trim($taxYear);
    $period = (int)trim($taxPeriod);
    if ($year < 2000 || $period < 1 || $period > 12) {
        return null;
    }
    $month = $period + 3;
    if ($month > 12) {
        $month -= 12;
    } else {
        $year--;
    }
    return sprintf('%04d-%02d', $year, $month);
}

function import_vat_transaction_code(string $transactionType): string
{
    foreach (['201', '204', '129', '481', '304'] as $code) {
        $pattern = '/(?<!\d)'.implode('\\D{0,8}', str_split($code)).'(?!\d)/u';
        if (preg_match($pattern, $transactionType)) {
            return $code;
        }
    }
    return '';
}

function import_vat_classification(string $transactionType): string
{
    $code = import_vat_transaction_code($transactionType);
    $map = [
        '201' => 'assessment',
        '204' => 'revision',
        '129' => 'payment',
        '481' => 'ignored_penalty',
        '304' => 'ignored_interest',
    ];
    return $map[$code] ?? 'needs_review';
}

function import_vat_statement_row(array $source, int $rowNumber): array
{
    $transactionType = trim((string)($source['transaction_type'] ?? ''));
    $classification = import_vat_classification($transactionType);
    $included = in_array($classification, ['assessment', 'revision', 'payment'], true);
    $kind = in_array($classification, ['assessment', 'revision'], true)
        ? 'liability'
        : ($classification === 'payment' ? 'payment' : 'unknown');
    $amount = abs(import_vat_money($source['transaction_amount'] ?? 0));
    $doc = trim((string)($source['doc_number'] ?? ''));
    $taxYear = trim((string)($source['tax_year'] ?? ''));
    $taxPeriod = trim((string)($source['tax_period'] ?? ''));
    $dueDate = import_vat_normal_date(trim((string)($source['due_date'] ?? '')));
    $effectiveDate = import_vat_normal_date(trim((string)($source['effective_date'] ?? '')));
    $actionDate = import_vat_normal_date(trim((string)($source['action_date'] ?? '')));
    $fingerprint = hash('sha256', implode('|', [
        trim((string)($source['tax_type'] ?? '')),
        preg_replace('/\s+/u', ' ', $transactionType),
        trim((string)($source['liability_type'] ?? '')),
        $doc,
        $taxYear,
        $taxPeriod,
        $dueDate,
        $effectiveDate,
        $actionDate,
        number_format($amount, 2, '.', ''),
    ]));

    return [
        'source_row_number' => $rowNumber,
        'transaction_date' => $actionDate ?: $effectiveDate,
        'due_date' => $dueDate,
        'reference' => $doc,
        'description' => preg_replace('/\s+/u', ' ', $transactionType),
        'debit' => $kind === 'liability' ? $amount : 0,
        'credit' => $classification === 'payment' ? $amount : 0,
        'import_vat_amount' => $kind === 'liability' ? $amount : 0,
        'other_charge_amount' => 0,
        'payment_amount' => $classification === 'payment' ? $amount : 0,
        'row_kind' => $kind,
        'confidence' => $classification === 'needs_review' ? 'low' : 'high',
        'match_status' => $classification === 'needs_review' ? 'needs_review' : ($included ? 'new' : 'excluded'),
        'tax_type' => trim((string)($source['tax_type'] ?? 'Value Added Tax Import Account')),
        'transaction_type' => preg_replace('/\s+/u', ' ', $transactionType),
        'liability_type' => trim((string)($source['liability_type'] ?? '')),
        'doc_number' => $doc,
        'tax_year' => $taxYear,
        'tax_period' => $taxPeriod,
        'effective_date' => $effectiveDate,
        'action_date' => $actionDate,
        'transaction_amount' => $amount,
        'classification' => $classification,
        'included_in_payable' => $included ? 1 : 0,
        'source_hash' => $fingerprint,
        'waiver_status' => in_array($classification, ['ignored_penalty', 'ignored_interest'], true) ? 'pending_waiver' : null,
        'source_json' => json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

/** Parse text extracted from the confirmed NamRA Transaction Records layout. */
function import_vat_namra_text_rows(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    if (stripos($text, 'Transaction Records') === false || stripos($text, 'Tax Type') === false || stripos($text, 'Tax Year') === false) {
        throw new RuntimeException('The PDF is not the confirmed NamRA Transaction Records format. No records were posted.');
    }

    $rows = [];
    $logical = [];
    $buffer = '';
    $hasTail = function (string $value): bool {
        return (bool)preg_match('/\b20\d{2}\s+\d{1,2}\s+\d{2}-\d{2}-\d{4}\s+\d{2}-\d{2}-\d{4}\s+\d{2}-\d{2}-\d{4}\s+[\d,]+\.\d{2}\s*$/', $value);
    };
    foreach (preg_split('/\n+/', $text) as $line) {
        $line = trim(preg_replace('/[\x{00A0}\t ]+/u', ' ', (string)$line));
        if ($line === '') {
            continue;
        }
        if (import_vat_transaction_code($line) !== '') {
            if ($buffer !== '' && $hasTail($buffer)) {
                $logical[] = $buffer;
            }
            $buffer = $line;
        } elseif ($buffer !== '' && !preg_match('/^(Namibia Revenue Agency|Transaction Records|TIN:|Taxpayer Name:|Tax Type)/i', $line)) {
            $buffer .= ' '.$line;
        }
        if ($buffer !== '' && $hasTail($buffer)) {
            $logical[] = $buffer;
            $buffer = '';
        }
    }

    $number = 1;
    foreach ($logical as $line) {
        if (!preg_match('/^(?<head>.*?)\s+(?<year>20\d{2})\s+(?<period>\d{1,2})\s+(?<due>\d{2}-\d{2}-\d{4})\s+(?<effective>\d{2}-\d{2}-\d{4})\s+(?<action>\d{2}-\d{2}-\d{4})\s+(?<amount>[\d,]+\.\d{2})\s*$/u', $line, $match)) {
            continue;
        }
        $code = import_vat_transaction_code($match['head']);
        if ($code === '') {
            $code = 'unknown';
        }
        $labels = [
            '201' => ['201 - Assessment(original)', 'Tax'],
            '204' => ['204 - Assessment(revision increased debit)', 'Tax'],
            '129' => ['129 - VIA payment', 'Tax'],
            '481' => ['481 - Late Payment Penalty', 'Late Payment Penalty'],
            '304' => ['304 - Interest on Debit', 'Late Payment Interest'],
        ];
        $transaction = $labels[$code][0] ?? preg_replace('/\s+/u', ' ', trim($match['head']));
        $liability = $labels[$code][1] ?? 'Needs review';
        $doc = '';
        if ($code === '129' && preg_match('/129\s*-\s*VIA\s+payment\s+Tax\s+(?<doc>.+)$/iu', $match['head'], $docMatch)) {
            $doc = preg_replace('/\s+/u', '', trim($docMatch['doc']));
        } elseif (preg_match('/(?<doc>[A-Z0-9]+)\s*$/u', $match['head'], $docMatch)) {
            $doc = $docMatch['doc'];
        }
        $rows[] = import_vat_statement_row([
            'tax_type' => 'Value Added Tax Import Account',
            'transaction_type' => $transaction,
            'liability_type' => $liability,
            'doc_number' => $doc,
            'tax_year' => $match['year'],
            'tax_period' => $match['period'],
            'due_date' => $match['due'],
            'effective_date' => $match['effective'],
            'action_date' => $match['action'],
            'transaction_amount' => $match['amount'],
            'raw_line' => $line,
        ], ++$number);
    }

    if (!$rows) {
        throw new RuntimeException('No NamRA transaction rows could be extracted from this PDF. No records were posted.');
    }
    return $rows;
}

function import_vat_extract_pdf_text(string $path): array
{
    $autoload = defined('BASE_PATH') ? BASE_PATH.'/vendor/autoload.php' : dirname(__DIR__).'/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    if (class_exists('Smalot\\PdfParser\\Parser')) {
        $parser = new Smalot\PdfParser\Parser();
        $text = trim($parser->parseFile($path)->getText());
        if ($text !== '') {
            return ['text' => $text, 'engine' => 'smalot/pdfparser'];
        }
    }
    if (function_exists('shell_exec')) {
        $output = shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>&1');
        if (is_string($output) && trim($output) !== '' && stripos($output, 'not found') === false && stripos($output, 'not recognized') === false) {
            return ['text' => trim($output), 'engine' => 'pdftotext'];
        }
    }
    throw new RuntimeException('PDF text extraction is unavailable on this server. Upload the NamRA statement as CSV or contact the owner. No records were posted.');
}

function import_vat_pdf_rows(string $path): array
{
    $attempts = [];
    $autoload = defined('BASE_PATH') ? BASE_PATH.'/vendor/autoload.php' : dirname(__DIR__).'/vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
    if (class_exists('Smalot\\PdfParser\\Parser')) {
        try {
            $parser = new Smalot\PdfParser\Parser();
            $text = trim($parser->parseFile($path)->getText());
            if ($text !== '') $attempts[] = ['text'=>$text,'engine'=>'smalot/pdfparser'];
        } catch (Throwable $error) {
            error_log('Import VAT Smalot extraction failed: '.$error->getMessage());
        }
    }
    if (function_exists('shell_exec')) {
        $output = shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>&1');
        if (is_string($output) && trim($output) !== '' && stripos($output, 'not found') === false && stripos($output, 'not recognized') === false) {
            $attempts[] = ['text'=>trim($output),'engine'=>'pdftotext'];
        }
    }
    $messages = [];
    foreach ($attempts as $attempt) {
        try {
            return ['rows'=>import_vat_namra_text_rows($attempt['text']),'engine'=>$attempt['engine']];
        } catch (Throwable $error) {
            $messages[] = $attempt['engine'].': '.$error->getMessage();
        }
    }
    if (!$attempts) throw new RuntimeException('PDF text extraction is unavailable on this server. Upload the NamRA statement as CSV or contact the owner. No records were posted.');
    throw new RuntimeException('The PDF text was read, but no validated NamRA transaction rows were found. No records were posted. '.implode(' ', $messages));
}
