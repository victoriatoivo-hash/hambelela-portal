<?php

declare(strict_types=1);

function normalize_unit(string $unit): string
{
    $unit = strtolower(trim($unit));
    $unit = rtrim($unit, '.');

    $aliases = [
        'kgs' => 'kg',
        'kilogram' => 'kg',
        'kilograms' => 'kg',
        'grams' => 'g',
        'gram' => 'g',
        'litre' => 'l',
        'litres' => 'l',
        'liter' => 'l',
        'liters' => 'l',
        'millilitre' => 'ml',
        'millilitres' => 'ml',
        'milliliter' => 'ml',
        'milliliters' => 'ml',
        'pcs' => 'unit',
        'piece' => 'unit',
        'pieces' => 'unit',
        'units' => 'unit',
    ];

    return $aliases[$unit] ?? $unit;
}

function unit_conversion_factor(string $fromUnit, string $toUnit): ?float
{
    $from = normalize_unit($fromUnit);
    $to = normalize_unit($toUnit);

    if ($from === $to || $from === '') {
        return 1.0;
    }

    $toBase = [
        'kg' => ['dimension' => 'mass', 'factor' => 1000.0],
        'g' => ['dimension' => 'mass', 'factor' => 1.0],
        'l' => ['dimension' => 'volume', 'factor' => 1000.0],
        'ml' => ['dimension' => 'volume', 'factor' => 1.0],
        'unit' => ['dimension' => 'count', 'factor' => 1.0],
    ];

    if (!isset($toBase[$from], $toBase[$to])) {
        return null;
    }

    if ($toBase[$from]['dimension'] !== $toBase[$to]['dimension']) {
        return null;
    }

    return $toBase[$from]['factor'] / $toBase[$to]['factor'];
}

function base_unit_for(string $unit): string
{
    $unit = normalize_unit($unit);

    if (in_array($unit, ['kg', 'g'], true)) {
        return 'g';
    }

    if (in_array($unit, ['l', 'ml'], true)) {
        return 'ml';
    }

    return 'unit';
}

function to_base_quantity(float $quantity, string $unit): array
{
    $baseUnit = base_unit_for($unit);
    $conversion = converted_quantity($quantity, $unit, $baseUnit);

    return [
        'quantity' => $conversion['quantity'],
        'unit' => $baseUnit,
        'converted' => $conversion['converted'],
        'message' => $conversion['message'],
    ];
}

function cost_per_base_unit(float $totalCost, float $quantity, string $unit): array
{
    $base = to_base_quantity($quantity, $unit);
    $baseQuantity = (float) $base['quantity'];

    return [
        'cost' => $baseQuantity > 0 ? $totalCost / $baseQuantity : 0.0,
        'unit' => $base['unit'],
        'converted' => $base['converted'],
        'message' => $base['message'],
    ];
}

function converted_quantity(float $quantity, string $fromUnit, string $toUnit): array
{
    $factor = unit_conversion_factor($fromUnit, $toUnit);

    if ($factor === null) {
        return [
            'quantity' => $quantity,
            'converted' => false,
            'message' => 'Unit conversion needed',
        ];
    }

    return [
        'quantity' => $quantity * $factor,
        'converted' => true,
        'message' => '',
    ];
}
