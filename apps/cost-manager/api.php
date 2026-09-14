<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/woocommerce.php';

require_login();

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'summary';

if ($action === 'invoice-preview') {
    echo json_encode([
        'supplier' => $_POST['supplier_name'] ?? null,
        'transport_cost' => (float) ($_POST['transport_cost'] ?? 0),
        'extracts' => [
            'raw_materials' => [],
            'packaging' => [],
            'transport' => [],
        ],
        'next_step' => 'Connect PDF text extraction and persist rows to raw_materials, packaging, and transport tables.',
    ]);
    exit;
}

if ($action === 'transport-preview') {
    echo json_encode([
        'supplier' => $_POST['supplier_name'] ?? null,
        'allocation_basis' => $_POST['allocation_basis'] ?? 'order_weight',
        'link' => [
            'type' => $_POST['link_type'] ?? 'supplier_invoice',
            'value' => $_POST['link_value'] ?? null,
        ],
        'extracts' => [
            'transport_invoice' => [
                'provider' => null,
                'invoice_number' => null,
                'invoice_date' => null,
                'waybill_number' => null,
                'consignment_number' => null,
                'route' => null,
                'pieces' => null,
                'actual_weight_kg' => null,
                'chargeable_weight_kg' => null,
                'subtotal' => null,
                'vat_amount' => null,
                'total_cost' => null,
            ],
            'allocations' => [
                'basis' => $_POST['allocation_basis'] ?? 'order_weight',
                'weight_source' => 'chargeable_weight_kg if present, otherwise actual_weight_kg',
                'rows' => [],
            ],
        ],
        'next_step' => 'Run PDF extraction, confirm extracted weight and charges, then allocate transport cost into product COGS.',
    ]);
    exit;
}

if ($action === 'woo-search') {
    $query = trim((string) ($_GET['q'] ?? ''));
    if ($query === '' || strlen($query) < 2) {
        echo json_encode(['products' => []]);
        exit;
    }

    try {
        $products = wc_get('products', [
            'search' => $query,
            'per_page' => 10,
            'status' => 'publish',
        ]);

        $results = [];
        foreach ($products as $product) {
            $type = (string) ($product['type'] ?? 'simple');
            $base = [
                'id' => (int) ($product['id'] ?? 0),
                'variation_id' => null,
                'name' => (string) ($product['name'] ?? ''),
                'sku' => (string) ($product['sku'] ?? ''),
                'price' => (float) ($product['price'] ?? $product['regular_price'] ?? 0),
                'stock_quantity' => isset($product['stock_quantity']) ? (int) $product['stock_quantity'] : 0,
                'variation' => '',
                'type' => $type,
            ];

            if ($type !== 'variable') {
                $results[] = $base;
                continue;
            }

            $variations = wc_get('products/' . (int) $product['id'] . '/variations', [
                'per_page' => 50,
                'status' => 'publish',
            ]);

            if (!$variations) {
                $results[] = $base;
                continue;
            }

            foreach ($variations as $variation) {
                $attrs = [];
                foreach (($variation['attributes'] ?? []) as $attr) {
                    if (!empty($attr['option'])) {
                        $attrs[] = (string) $attr['option'];
                    }
                }
                $variationLabel = implode(' / ', $attrs);
                $results[] = [
                    'id' => (int) ($product['id'] ?? 0),
                    'variation_id' => (int) ($variation['id'] ?? 0),
                    'name' => trim((string) ($product['name'] ?? '') . ($variationLabel ? ' - ' . $variationLabel : '')),
                    'sku' => (string) ($variation['sku'] ?? $product['sku'] ?? ''),
                    'price' => (float) ($variation['price'] ?? $variation['regular_price'] ?? 0),
                    'stock_quantity' => isset($variation['stock_quantity']) ? (int) $variation['stock_quantity'] : 0,
                    'variation' => $variationLabel,
                    'type' => 'variation',
                ];
            }
        }

        echo json_encode(['products' => array_values($results)]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'products' => []]);
    }
    exit;
}

echo json_encode([
    'sales_imported' => 38420,
    'total_cogs' => 21884,
    'gross_profit' => 16536,
    'average_margin' => 43.0,
]);
