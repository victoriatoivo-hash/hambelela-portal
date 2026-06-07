<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/units.php';
require_once __DIR__ . '/engines/cost-engine.php';

function component_landed_unit_cost(PDO $pdo, string $type, int $componentId): array
{
    $row = cost_engine_component_cost($pdo, $type, $componentId);

    return [
        'unit' => (string) ($row['unit'] ?? 'unit'),
        'cost' => (float) ($row['landed_unit_cost'] ?? 0),
    ];
}

function recipe_unit_cogs(PDO $pdo, int $recipeId): float
{
    $stmt = $pdo->prepare('SELECT fp.*, pr.id AS recipe_id FROM product_recipes pr JOIN finished_products fp ON fp.id = pr.product_id WHERE pr.id = ?');
    $stmt->execute([$recipeId]);
    $product = $stmt->fetch();
    if (!$product) {
        return 0.0;
    }

    return (float) cost_engine_product_breakdown($pdo, $product)['total_cogs'];
}

function raw_resale_unit_cogs(PDO $pdo, array $product): float
{
    return (float) cost_engine_product_breakdown($pdo, $product)['total_cogs'];
}

function product_unit_cogs(PDO $pdo, array $product): float
{
    if (($product['costing_type'] ?? 'recipe') === 'raw_resale') {
        return raw_resale_unit_cogs($pdo, $product);
    }

    return recipe_unit_cogs($pdo, (int) $product['recipe_id']);
}
