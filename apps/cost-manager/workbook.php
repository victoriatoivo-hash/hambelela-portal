<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_login();

$pageTitle = 'Cost Management System | ' . APP_NAME;
$activeApp = 'cost-manager';
$stats = [
    'products' => 0,
    'supplier_invoices' => 0,
    'transport_invoices' => 0,
    'packaging_items' => 0,
    'woo_sales' => 0,
    'unlinked_products' => 0,
];
$error = null;

try {
    $pdo = db();
    $stats['products'] = (int) $pdo->query('SELECT COUNT(*) FROM finished_products')->fetchColumn();
    $stats['supplier_invoices'] = (int) $pdo->query('SELECT COUNT(*) FROM supplier_invoices')->fetchColumn();
    $stats['transport_invoices'] = (int) $pdo->query('SELECT COUNT(*) FROM transport_invoices')->fetchColumn();
    $stats['packaging_items'] = (int) $pdo->query('SELECT COUNT(*) FROM packaging')->fetchColumn();
    $stats['woo_sales'] = (int) $pdo->query('SELECT COUNT(*) FROM woo_sales')->fetchColumn();
    $stats['unlinked_products'] = (int) $pdo->query('SELECT COUNT(*) FROM finished_products WHERE woo_product_id IS NULL OR woo_product_id = 0')->fetchColumn();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$sections = [
    [
        'title' => 'Dashboard',
        'desc' => 'High-level overview of invoices, margins, missing costs, website links, inventory value, and profitability warnings.',
        'icon' => 'layout-dashboard',
        'href' => 'system-dashboard.php',
        'status' => 'Foundation',
        'metric' => number_format($stats['products']) . ' products',
    ],
    [
        'title' => 'Supplier Invoice Manager',
        'desc' => 'Upload supplier invoices, extract product names, quantities, units, costs, VAT, totals, then approve the corrected invoice.',
        'icon' => 'file-scan',
        'href' => 'upload-invoice.php',
        'status' => 'Active',
        'metric' => number_format($stats['supplier_invoices']) . ' invoices',
    ],
    [
        'title' => 'Transport Invoice Manager',
        'desc' => 'Upload transport invoices, extract transport costs and weights, then allocate costs to supplier invoice product lines.',
        'icon' => 'truck',
        'href' => 'transport.php',
        'status' => 'Active',
        'metric' => number_format($stats['transport_invoices']) . ' invoices',
    ],
    [
        'title' => 'Packaging Cost Database',
        'desc' => 'Track packaging items bought, suppliers, quantities, unit cost, stock left, and which products use each item.',
        'icon' => 'package-check',
        'href' => 'packaging-manager.php',
        'status' => 'Build next',
        'metric' => number_format($stats['packaging_items']) . ' items',
    ],
    [
        'title' => 'Cost Workbook / Landing Cost Engine',
        'desc' => 'Central landed cost table: supplier cost, transport, packaging, extra costs, SKU, category, cost per kg/L and cost per g/ml.',
        'icon' => 'table-2',
        'href' => 'landing-cost-engine.php',
        'status' => 'Core',
        'metric' => 'Source of truth',
    ],
    [
        'title' => 'Profit Calculator',
        'desc' => 'Calculate cost, selling price, VAT, profit, margin, markup, retail price, wholesale price, and reseller price by product size.',
        'icon' => 'calculator',
        'href' => 'profit-calculator.php',
        'status' => 'Build next',
        'metric' => 'Margin playground',
    ],
    [
        'title' => 'Website Profit & Inventory Analysis',
        'desc' => 'Sync WooCommerce prices, product links, variations, stock quantities, and estimate profit sitting in website inventory.',
        'icon' => 'shopping-cart',
        'href' => 'inventory-profit.php',
        'status' => 'Build next',
        'metric' => number_format($stats['woo_sales']) . ' sales lines',
    ],
    [
        'title' => 'Formulation Costing System',
        'desc' => 'Create formulations using landed raw-material costs, ingredient percentages, batch cost, packaging, and unit manufacturing cost.',
        'icon' => 'flask-conical',
        'href' => 'recipes.php',
        'status' => 'Active',
        'metric' => '3 formulated products',
    ],
    [
        'title' => 'Retail & Reseller Pricing Manager',
        'desc' => 'Manage retail, wholesale, reseller, distributor, category margins, bulk margin rules, and manual overrides.',
        'icon' => 'badge-dollar-sign',
        'href' => 'pricing-manager.php',
        'status' => 'Build next',
        'metric' => 'Pricing rules',
    ],
    [
        'title' => 'Product Database',
        'desc' => 'Central product master for product names, SKU, short code, category, supplier, WooCommerce link, active status, and current cost.',
        'icon' => 'database',
        'href' => 'product-database.php',
        'status' => 'Build next',
        'metric' => number_format($stats['unlinked_products']) . ' unlinked',
    ],
    [
        'title' => 'Reports',
        'desc' => 'Monthly profitability, product margin, inventory valuation, supplier spend, VAT, landed cost, and low-margin reports.',
        'icon' => 'chart-no-axes-combined',
        'href' => 'profit-report.php',
        'status' => 'Active',
        'metric' => 'Profit reports',
    ],
    [
        'title' => 'Settings & Automation',
        'desc' => 'Configure AI extraction, OCR fallback, WooCommerce sync, SKU rules, matching rules, roles, audit history, and scheduled jobs.',
        'icon' => 'settings',
        'href' => 'settings.php',
        'status' => 'Build later',
        'metric' => 'Automation',
    ],
];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module cost-system">
    <section class="module-header cost-system-header">
        <div>
            <p class="eyebrow">Hambelela Organic</p>
            <h1>Cost Management & Inventory Intelligence</h1>
            <p>This is the new structure from the blueprint: one connected system where invoices feed landed costs, landed costs feed product pricing, and product pricing feeds website profit and inventory analysis.</p>
        </div>
        <div class="actions">
            <a class="button" href="../../index.php"><i data-lucide="arrow-left"></i> Portal</a>
            <a class="button primary" href="upload-invoice.php"><i data-lucide="file-up"></i> Upload supplier invoice</a>
        </div>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php endif; ?>

    <section class="system-flow">
        <div><span>1</span><strong>Invoices</strong><small>Supplier, transport, packaging</small></div>
        <div><span>2</span><strong>Allocations</strong><small>Transport, packaging, extras</small></div>
        <div><span>3</span><strong>Landed Cost</strong><small>SKU, category, kg/L, g/ml</small></div>
        <div><span>4</span><strong>Pricing</strong><small>Retail, wholesale, reseller</small></div>
        <div><span>5</span><strong>Website</strong><small>WooCommerce price and stock</small></div>
        <div><span>6</span><strong>Profit</strong><small>Margins, VAT, reports</small></div>
    </section>

    <section class="system-grid" aria-label="Cost management modules">
        <?php foreach ($sections as $section): ?>
            <a class="system-card" href="<?= htmlspecialchars($section['href'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="system-card-top">
                    <span class="system-icon"><i data-lucide="<?= htmlspecialchars($section['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                    <span class="status"><?= htmlspecialchars($section['status'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <h2><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars($section['desc'], ENT_QUOTES, 'UTF-8') ?></p>
                <small><?= htmlspecialchars($section['metric'], ENT_QUOTES, 'UTF-8') ?></small>
            </a>
        <?php endforeach; ?>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
