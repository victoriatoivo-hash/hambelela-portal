<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';

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

echo json_encode([
    'sales_imported' => 38420,
    'total_cogs' => 21884,
    'gross_profit' => 16536,
    'average_margin' => 43.0,
]);
