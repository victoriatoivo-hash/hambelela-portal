<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/accounts-input-vat-calculator.php';

function assert_money(float $expected, float $actual, string $label): void
{
    if (abs($expected - $actual) > 0.001) {
        fwrite(STDERR, $label . ': expected ' . number_format($expected, 2) . ', got ' . number_format($actual, 2) . PHP_EOL);
        exit(1);
    }
}

$inclusive = accounts_vat_calculate_values(1000.00, 'inclusive', 'mixed', 15.0, 200.00);
assert_money(800.00, $inclusive['standard_rated_inclusive'], 'Test A standard incl');
assert_money(104.35, $inclusive['vat'], 'Test A VAT');
assert_money(695.65, $inclusive['standard_rated_exclusive'], 'Test A standard excl');
assert_money(895.65, $inclusive['exclusive'], 'Test A total excl');

$standardOnlyPrompt = accounts_vat_calculate_values(500.00, 'inclusive', 'mixed', 15.0, 0.00);
assert_money(65.22, $standardOnlyPrompt['vat'], 'Test B zero-zero mixed VAT');

$zeroOnlyPrompt = accounts_vat_calculate_values(500.00, 'inclusive', 'mixed', 15.0, 500.00);
assert_money(0.00, $zeroOnlyPrompt['vat'], 'Test C full zero-rated VAT');
assert_money(500.00, $zeroOnlyPrompt['exclusive'], 'Test C full zero-rated excl');

try {
    accounts_vat_calculate_values(500.00, 'inclusive', 'mixed', 15.0, 600.00);
    fwrite(STDERR, "Test D did not reject a zero-rated amount above the invoice total.\n");
    exit(1);
} catch (InvalidArgumentException $error) {
    if ($error->getMessage() !== 'Zero-rated amount cannot be greater than the invoice total.') {
        fwrite(STDERR, 'Test D returned the wrong validation message.' . PHP_EOL);
        exit(1);
    }
}

$exclusive = accounts_vat_calculate_values(1000.00, 'exclusive', 'mixed', 15.0, 200.00);
assert_money(800.00, $exclusive['standard_rated_exclusive'], 'Test E standard excl');
assert_money(120.00, $exclusive['vat'], 'Test E VAT');
assert_money(1120.00, $exclusive['inclusive'], 'Test E total incl');

$standard = accounts_vat_calculate_values(115.00, 'inclusive', 'standard', 15.0);
assert_money(15.00, $standard['vat'], 'Standard VAT regression');
$zero = accounts_vat_calculate_values(115.00, 'inclusive', 'zero_rated', 15.0);
assert_money(0.00, $zero['vat'], 'Zero-rated regression');
$none = accounts_vat_calculate_values(115.00, 'inclusive', 'no_vat', 15.0);
assert_money(0.00, $none['vat'], 'No VAT regression');

echo "Input VAT mixed calculation tests passed.\n";
