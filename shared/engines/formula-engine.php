<?php

declare(strict_types=1);

require_once __DIR__ . '/cost-engine.php';

function formula_engine_recipe_cost(PDO $pdo, int $recipeId): array
{
    $stmt = $pdo->prepare(
        'SELECT fp.id AS product_id, fp.name, fp.sku, fp.selling_price, fp.costing_type,
                pr.id AS recipe_id, pr.version
         FROM product_recipes pr
         JOIN finished_products fp ON fp.id = pr.product_id
         WHERE pr.id = ?'
    );
    $stmt->execute([$recipeId]);
    $product = $stmt->fetch();

    if (!$product) {
        return ['product' => null, 'breakdown' => ['lines' => [], 'total_cogs' => 0.0]];
    }

    return [
        'product' => $product,
        'breakdown' => cost_engine_product_breakdown($pdo, $product),
    ];
}
