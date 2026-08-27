<?php
declare(strict_types=1);

function cw_page_routes(): array
{
    return [
        'overview' => ['label' => 'Overview', 'route' => 'workbook.php', 'title' => 'Cost Workbook', 'description' => 'Manage product sizes, purchase costs, packaging, margins and selling-price calculations.'],
        'size-conversions' => ['label' => 'Size Conversions', 'route' => 'size-conversions.php', 'title' => 'Size Conversions', 'description' => 'Standard product sizes converted into litres or kilograms for accurate costing calculations.'],
        'supplier-invoices' => ['label' => 'Supplier Invoices', 'route' => 'supplier-invoices.php', 'title' => 'Supplier Invoices & Cost Extraction', 'description' => 'Upload supplier invoices, extract purchased products and review costs by supplier and invoice date.'],
        'transport-costs' => ['label' => 'Transport Costs', 'route' => 'transport-costs.php', 'title' => 'Transport Cost Allocation', 'description' => 'Record transport charges, link supplier invoices and allocate delivery costs using an auditable method.'],
        'packaging-costs' => ['label' => 'Packaging Costs', 'route' => 'packaging-costs.php', 'title' => 'Packaging Costs', 'description' => 'Record packaging materials, calculate unit costs and assign containers and labels to product sizes.'],
        'landed-product-costs' => ['label' => 'Landed Product Costs', 'route' => 'landed-product-costs.php', 'title' => 'Landed Product Costs', 'description' => 'Review supplier costs, allocated transport and the resulting landed cost for every purchased product.'],
        'formulations' => ['label' => 'Formulation Costing', 'route' => 'formulations.php', 'title' => 'Formulation Builder & Pricing', 'description' => 'Build formulations, calculate batch and unit costs, and generate recommended selling prices.'],
        'wholesale-pricing' => ['label' => 'Wholesale Pricing', 'route' => 'wholesale-pricing.php', 'title' => 'Wholesale Pricing', 'description' => 'Create profitable wholesale prices for bulk weight, volume, count and ready-made product sizes.'],
        'purchases' => ['label' => 'Legacy Purchases', 'route' => 'purchases.php', 'title' => 'Legacy Purchases & Invoices', 'description' => 'Existing invoice review workspace retained for compatibility.'],
        'shipments' => ['label' => 'Shipments', 'route' => 'shipments.php', 'title' => 'Shipments & Expenses', 'description' => 'Link approved invoices and record shipment expenses.'],
        'landed-costs' => ['label' => 'Landed Costs', 'route' => 'landed-costs.php', 'title' => 'Landed Costs', 'description' => 'Allocate and reconcile landed costs for the selected period.'],
        'product-matching' => ['label' => 'Product Matching', 'route' => 'product-matching.php', 'title' => 'Product Matching', 'description' => 'Match calculated sale sizes to the read-only website snapshot.'],
        'profitability' => ['label' => 'Profitability', 'route' => 'profitability.php', 'title' => 'Profitability', 'description' => 'Review private cost, price, profit, margin and markup previews.'],
        'cogs-publishing' => ['label' => 'COGS Publishing', 'route' => 'cogs-publishing.php', 'title' => 'COGS Publishing', 'description' => 'Review native WooCommerce COGS eligibility and safety requirements.'],
        'settings' => ['label' => 'Settings', 'route' => 'settings.php', 'title' => 'Cost Workbook Settings', 'description' => 'Manage Cost Workbook calculation and display settings.'],
        'historical' => ['label' => 'Historical Records', 'route' => 'historical-cost-records.php', 'title' => 'Historical Cost Records', 'description' => 'View preserved records from the previous Landing Cost system.'],
    ];
}

function cw_page_period(): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek'));
    $yearRaw = isset($_GET['year']) ? (string) $_GET['year'] : '';
    $monthRaw = isset($_GET['month']) ? (string) $_GET['month'] : '';
    $year = $yearRaw !== '' && preg_match('/^\d{4}$/', $yearRaw) ? (int) $yearRaw : null;
    $month = $monthRaw !== '' && preg_match('/^\d{1,2}$/', $monthRaw) ? (int) $monthRaw : null;
    if ((isset($_GET['year']) && ($year === null || $year < 2020 || $year > 2100)) || (isset($_GET['month']) && ($month === null || $month < 1 || $month > 12))) {
        http_response_code(400);
        exit('The selected Cost Workbook period is invalid.');
    }
    $year = $year ?: (int) $now->format('Y');
    $month = $month ?: (int) $now->format('n');
    return ['year' => $year, 'month' => $month, 'id' => sprintf('%04d-%02d', $year, $month)];
}

function cw_page_url(string $route, array $period, array $extra = []): string
{
    return BASE_URL . '/apps/cost-manager/' . $route . '?' . http_build_query(array_merge(['year' => $period['year'], 'month' => $period['month']], $extra));
}

function cw_page_begin(string $key, array $period, ?string $bootError): void
{
    $routes = cw_page_routes();
    if (!isset($routes[$key])) { http_response_code(404); exit('Cost Workbook page not found.'); }
    $page = $routes[$key];
    $GLOBALS['pageTitle'] = $page['title'] . ' | ' . APP_NAME;
    $GLOBALS['activeApp'] = 'cost-manager';
    include BASE_PATH . '/shared/header.php';
    include BASE_PATH . '/shared/sidebar.php';
    ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook.css?v=3">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-invoice.css?v=3">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-phase2.css?v=2">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-pages.css?v=2">
    <?php if($key==='supplier-invoices'): ?><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-supplier-invoices.css?v=<?= (int) filemtime(BASE_PATH.'/assets/css/cost-workbook-supplier-invoices.css') ?>"><?php endif; ?>
    <?php if($key==='packaging-costs'): ?><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-packaging-costs.css?v=<?= (int) filemtime(BASE_PATH.'/assets/css/cost-workbook-packaging-costs.css') ?>"><?php endif; ?>
    <?php if($key==='landed-product-costs'): ?><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-landed-product-costs.css?v=<?= (int) filemtime(BASE_PATH.'/assets/css/cost-workbook-landed-product-costs.css') ?>"><?php endif; ?>
    <?php if($key==='formulations'): ?><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-formulation-costing.css?v=<?= (int) filemtime(BASE_PATH.'/assets/css/cost-workbook-formulation-costing.css') ?>"><?php endif; ?>
    <?php if($key==='wholesale-pricing'): ?><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-wholesale-pricing.css?v=<?= (int) filemtime(BASE_PATH.'/assets/css/cost-workbook-wholesale-pricing.css') ?>"><?php endif; ?>
    <?php if(in_array($key,['overview','size-conversions','supplier-invoices','packaging-costs','landed-product-costs','formulations','wholesale-pricing'],true)): ?><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/accounts-dashboard.css?v=<?= (int) filemtime(BASE_PATH.'/assets/css/accounts-dashboard.css') ?>"><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-landing.css?v=<?= (int) filemtime(BASE_PATH.'/assets/css/cost-workbook-landing.css') ?>"><?php endif; ?>
    <?php if($key==='transport-costs'): ?><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-transport-costs-v2.css?v=<?= (int) filemtime(BASE_PATH.'/assets/css/cost-workbook-transport-costs-v2.css') ?>"><?php endif; ?>
    <main class="workspace cw cw-page cost-workbook-page <?= in_array($key,['overview','size-conversions','supplier-invoices','transport-costs','packaging-costs','landed-product-costs','formulations','wholesale-pricing'],true)?'cost-workbook-app accounts-workspace':'' ?>" id="costWorkbook" data-page="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" data-api="cw-api.php" data-phase2-api="cw-phase2-api.php" data-csrf="<?= htmlspecialchars(cw_csrf_token(), ENT_QUOTES, 'UTF-8') ?>" data-year="<?= (int) $period['year'] ?>" data-month="<?= (int) $period['month'] ?>">
      <?php if($key==='overview'): ?><header class="accounts-hero cw-workspace-header"><div><p class="eyebrow">Costing workspace</p><h1>Cost Workbook</h1><p><?= htmlspecialchars($page['description'], ENT_QUOTES, 'UTF-8') ?></p></div></header>
      <?php elseif($key==='size-conversions'): ?><div class="size-conversions__toolbar"><a class="size-conversions__back" href="<?= cw_page_url('workbook.php',$period) ?>"><span aria-hidden="true">←</span> Back to Cost Workbook</a></div><header class="accounts-hero cw-workspace-header"><div class="size-conversions__header-row"><div class="size-conversions__heading-group"><p class="eyebrow">Cost Workbook</p><h1>Size Conversions</h1><p><?= htmlspecialchars($page['description'], ENT_QUOTES, 'UTF-8') ?></p></div><button class="size-conversions__add-button" type="button" data-size-conversion-add><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-linecap="round"/></svg>Add Conversion</button></div></header>
      <?php elseif($key==='supplier-invoices'): ?><div class="supplier-invoices__toolbar"><a class="supplier-invoices__back" href="<?= cw_page_url('workbook.php',$period) ?>"><span aria-hidden="true">←</span> Back to Cost Workbook</a></div><header class="accounts-hero cw-workspace-header supplier-invoices__header-row"><div class="supplier-invoices__heading-group"><p class="eyebrow">Cost Workbook</p><h1>Supplier Invoices &amp; Cost Extraction</h1><p><?= htmlspecialchars($page['description'], ENT_QUOTES, 'UTF-8') ?></p></div><a class="supplier-invoices__upload-button" href="#supplierInvoiceUpload"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14v5h14v-5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/></svg>Upload Invoice</a></header>
      <?php elseif($key==='transport-costs'): ?><div class="transport-costs__toolbar"><a href="<?= cw_page_url('workbook.php',$period) ?>"><span aria-hidden="true">←</span> Back to Cost Workbook</a></div><header class="accounts-hero cw-workspace-header transport-costs__header"><div><p class="eyebrow">Cost Workbook</p><h1>Transport Cost Allocation</h1><p><?= htmlspecialchars($page['description'], ENT_QUOTES, 'UTF-8') ?></p></div><div class="transport-costs__actions"><button type="button" class="portal-button transport-costs__header-button transport-costs__header-button--manual" data-transport-manual><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>Add Transport Manually</button><a class="portal-button transport-costs__header-button transport-costs__header-button--upload" href="#transportInvoiceUpload"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 15V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14v5h14v-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Upload Transport Invoice</a></div></header>
      <?php elseif($key==='packaging-costs'): ?><div class="packaging-costs__toolbar"><a href="<?= cw_page_url('workbook.php',$period) ?>">← Back to Cost Workbook</a></div><header class="accounts-hero cw-workspace-header packaging-costs__header"><div><p class="eyebrow">Cost Workbook</p><h1>Packaging Costs</h1><p><?= htmlspecialchars($page['description'],ENT_QUOTES,'UTF-8') ?></p></div><div class="packaging-costs__actions"><button type="button" class="portal-button portal-button--primary" data-pc-open-item>Add Packaging Item</button><button type="button" class="portal-button portal-button--secondary" data-pc-open-setup>Create Size Setup</button></div></header>
      <?php elseif($key==='landed-product-costs'): ?><div class="landed-product-costs__toolbar"><a href="<?= cw_page_url('workbook.php',$period) ?>">← Back to Cost Workbook</a></div><header class="accounts-hero cw-workspace-header landed-product-costs__header-row"><div><p class="eyebrow">Cost Workbook</p><h1>Landed Product Costs</h1><p><?= htmlspecialchars($page['description'],ENT_QUOTES,'UTF-8') ?></p></div><div class="landed-product-costs__header-actions"><button type="button" class="portal-button portal-button--secondary" data-lpc-add>Add Product Manually</button><button type="button" class="portal-button portal-button--primary" data-lpc-refresh>Refresh Costs</button></div></header>
      <?php elseif($key==='formulations'): ?><div class="formulation-costing__toolbar"><a href="<?= cw_page_url('workbook.php',$period) ?>">← Back to Cost Workbook</a></div><header class="accounts-hero cw-workspace-header formulation-costing__header"><div><p class="eyebrow">Cost Workbook</p><h1>Formulation Builder &amp; Pricing</h1><p><?= htmlspecialchars($page['description'],ENT_QUOTES,'UTF-8') ?></p></div><button type="button" class="portal-button portal-button--primary" onclick="document.querySelector('[data-fc-root] [data-fc-new]').click()">New Formulation</button></header>
      <?php elseif($key==='wholesale-pricing'): ?><div class="wholesale-pricing__toolbar"><a href="<?= cw_page_url('workbook.php',$period) ?>">← Back to Cost Workbook</a></div><header class="accounts-hero cw-workspace-header wholesale-pricing__header-row"><div><p class="eyebrow">Cost Workbook</p><h1>Wholesale Pricing</h1><p><?= htmlspecialchars($page['description'],ENT_QUOTES,'UTF-8') ?></p></div><div class="wholesale-pricing__header-actions"><button type="button" class="portal-button portal-button--secondary" data-wp-refresh>Refresh Costs</button><button type="button" class="portal-button portal-button--primary" data-wp-add>Add Wholesale Price</button></div></header>
      <?php else: ?><header class="cw-hero"><div><p class="cw-eyebrow">Hambelela Organic · <?= htmlspecialchars($period['id'], ENT_QUOTES, 'UTF-8') ?></p><h1><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></h1><p><?= htmlspecialchars($page['description'], ENT_QUOTES, 'UTF-8') ?></p></div><?php if($key==='purchases'): ?><div class="cw-hero-actions"><button class="cw-btn cw-primary" data-open-upload>Upload invoice</button></div><?php endif; ?></header><?php endif; ?>
      <?php if($key==='transport-costs'): ?><?php cw_render_transport_summary(); ?><?php endif; ?>
      <?php if ($bootError): ?><div class="cw-alert cw-error" role="alert"><strong>Cost Workbook setup needs attention.</strong> The workbook could not be prepared safely.</div><?php endif; ?>
      <div id="cwNotice" class="cw-alert" hidden></div>
      <?php if(!in_array($key,['overview','size-conversions','supplier-invoices'],true)): ?><nav class="cw-period-nav" aria-label="Cost Workbook period"><?php if($key==='transport-costs'): ?><span class="transport-costs__period-year"><small>Year</small><strong><?= (int)$period['year'] ?></strong></span><?php endif; ?><a href="<?= cw_page_url($page['route'], ['year'=>(int)(new DateTimeImmutable($period['id'].'-01'))->modify('-1 month')->format('Y'),'month'=>(int)(new DateTimeImmutable($period['id'].'-01'))->modify('-1 month')->format('n')]) ?>" aria-label="Previous month"><?php if($key==='transport-costs'): ?><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m14.5 6-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><?php else: ?>←<?php endif; ?></a><?php for($m=1;$m<=12;$m++): ?><a class="<?= $m===$period['month']?'is-active':'' ?>" <?= $m===$period['month']?'aria-current="date"':'' ?> href="<?= cw_page_url($page['route'], ['year'=>$period['year'],'month'=>$m]) ?>"><?= htmlspecialchars($key==='transport-costs'?strtoupper(DateTimeImmutable::createFromFormat('!m',(string)$m)->format('M')):DateTimeImmutable::createFromFormat('!m',(string)$m)->format('M'),ENT_QUOTES,'UTF-8') ?></a><?php endfor; ?><a href="<?= cw_page_url($page['route'], ['year'=>(int)(new DateTimeImmutable($period['id'].'-01'))->modify('+1 month')->format('Y'),'month'=>(int)(new DateTimeImmutable($period['id'].'-01'))->modify('+1 month')->format('n')]) ?>" aria-label="Next month"><?php if($key==='transport-costs'): ?><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9.5 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><?php else: ?>→<?php endif; ?></a></nav>
      <nav class="cw-steps" aria-label="Workbook sections"><?php foreach($routes as $routeKey=>$item): ?><a class="cw-section-link <?= $routeKey===$key?'is-active':'' ?>" <?= $routeKey===$key?'aria-current="page"':'' ?> href="<?= cw_page_url($item['route'],$period) ?>"><?= htmlspecialchars($item['label'],ENT_QUOTES,'UTF-8') ?></a><?php endforeach; ?></nav><?php endif; ?>
    <?php
}

function cw_page_end(array $scripts = []): void
{
    echo '</main>';
    foreach ($scripts as $script) {
        $defer = (strpos($script, 'supplier-invoices-v2.js') === 0 || strpos($script, 'transport-costs.js') === 0 || strpos($script, 'packaging-costs.js') === 0 || strpos($script, 'landed-product-costs.js') === 0 || strpos($script, 'formulation-costing.js') === 0) ? '' : ' defer';
        echo '<script src="' . htmlspecialchars(BASE_URL . '/assets/js/' . $script, ENT_QUOTES, 'UTF-8') . '"' . $defer . '></script>';
    }
    include BASE_PATH . '/shared/footer.php';
}
