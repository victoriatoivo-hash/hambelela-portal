<?php

declare(strict_types=1);

/**
 * Pure Input VAT calculation used by both the persistence layer and tests.
 * Amounts are rounded using the portal's existing two-decimal money rule.
 */
function accounts_vat_calculate_values(float $amount, string $source, string $treatment, float $rate, float $zeroRatedAmount = 0.0): array
{
    $amount = round(max(0.0, $amount), 2);
    $rate = max(0.0, min(100.0, $rate));
    $source = $source === 'exclusive' ? 'exclusive' : 'inclusive';
    $zeroRatedAmount = round(max(0.0, $zeroRatedAmount), 2);

    if ($treatment === 'mixed' && $zeroRatedAmount > $amount) {
        throw new InvalidArgumentException('Zero-rated amount cannot be greater than the invoice total.');
    }

    if ($treatment === 'mixed') {
        if ($source === 'exclusive') {
            $standardExclusive = round($amount - $zeroRatedAmount, 2);
            $vat = round($standardExclusive * $rate / 100, 2);
            $standardInclusive = round($standardExclusive + $vat, 2);
            $exclusive = $amount;
            $inclusive = round($exclusive + $vat, 2);
        } else {
            $standardInclusive = round($amount - $zeroRatedAmount, 2);
            $vat = round($standardInclusive * $rate / (100 + $rate), 2);
            $standardExclusive = round($standardInclusive - $vat, 2);
            $inclusive = $amount;
            $exclusive = round($standardExclusive + $zeroRatedAmount, 2);
        }

        return [
            'inclusive' => $inclusive,
            'vat' => $vat,
            'exclusive' => $exclusive,
            'rate' => $rate,
            'treatment' => 'mixed',
            'source' => $source,
            'standard_rated_inclusive' => $standardInclusive,
            'standard_rated_exclusive' => $standardExclusive,
            'zero_rated_amount' => $zeroRatedAmount,
        ];
    }

    $taxable = !in_array($treatment, ['zero_rated', 'no_vat'], true);
    if ($source === 'exclusive') {
        $exclusive = $amount;
        $vat = $taxable ? round($exclusive * $rate / 100, 2) : 0.0;
        $inclusive = round($exclusive + $vat, 2);
    } else {
        $inclusive = $amount;
        $vat = $taxable ? round($inclusive * $rate / (100 + $rate), 2) : 0.0;
        $exclusive = round($inclusive - $vat, 2);
    }

    return [
        'inclusive' => $inclusive,
        'vat' => $vat,
        'exclusive' => $exclusive,
        'rate' => $taxable ? $rate : 0.0,
        'treatment' => $treatment,
        'source' => $source,
        'standard_rated_inclusive' => $taxable ? $inclusive : 0.0,
        'standard_rated_exclusive' => $taxable ? $exclusive : 0.0,
        'zero_rated_amount' => $treatment === 'zero_rated' ? $exclusive : 0.0,
    ];
}
