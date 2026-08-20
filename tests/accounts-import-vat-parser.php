<?php
declare(strict_types=1);

function import_vat_money($value): float
{
    $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$value));
    return $clean === '' ? 0.0 : round((float)$clean, 2);
}
function import_vat_normal_date(string $value): ?string
{
    $value = trim($value);
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
        $date = DateTime::createFromFormat('!'.$format, $value);
        if ($date && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }
    return null;
}

require dirname(__DIR__).'/shared/accounts-import-vat-parser.php';

$fixture = <<<'TEXT'
Namibia Revenue Agency
Transaction Records
Tax Type Transaction Type Liability Type Doc No. Tax Year Tax Period Due Date Effective Date Action Date Transaction Amount
V Im a p lu o e rt A A d c d c e o d u n T t ax 2 A 0 s 1 se - ssment(original) Tax 29832776 2027 5 20-08-2026 20-08-2026 05-08-2026 12,273.53
Value Added Tax Import Account 204 - Assessment(revision increased debit) Tax 28926769 2026 9 22-12-2025 22-12-2025 02-12-2025 12,747.90
Value Added Tax Import Account 129 - VIA payment Tax B R 62 K E S V T _ M VA T T - 03112025- 2026 8 20-11-2025 03-11-2025 07-11-2025 6,224.63
Value Added Tax Import Account 4 P 8 e 1 n a - l L ty ate Payment Late Payment Penalty LPVIA230626001 2027 3 22-06-2026 01-08-2026 01-08-2026 1,069.42
Value Added Tax Import Account 304 - Interest on Debit Late Payment Interest LPVIA230626001 2027 3 22-06-2026 14-08-2026 14-08-2026 41.02
TEXT;

$rows = import_vat_namra_text_rows($fixture);
if (count($rows) !== 5) {
    throw new RuntimeException('Expected five parsed fixture rows, received '.count($rows).'.');
}
$expected = ['assessment', 'revision', 'payment', 'ignored_penalty', 'ignored_interest'];
if (array_column($rows, 'classification') !== $expected) {
    throw new RuntimeException('NamRA transaction classification mapping failed.');
}
if ($rows[2]['doc_number'] !== 'BR62KESVT_MVATT-03112025-') {
    throw new RuntimeException('Spaced NamRA payment document number was not normalised.');
}
if (import_vat_tax_period_month('2027', '5') !== '2026-08' || import_vat_tax_period_month('2026', '12') !== '2026-03') {
    throw new RuntimeException('NamRA Tax Year/Tax Period month mapping failed.');
}
if ((int)$rows[3]['included_in_payable'] !== 0 || (int)$rows[4]['included_in_payable'] !== 0) {
    throw new RuntimeException('Penalty/interest rows must be retained but excluded from payable totals.');
}
echo "Import VAT NamRA parser fixture passed\n";
