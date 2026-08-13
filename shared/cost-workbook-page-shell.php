<?php
declare(strict_types=1);

function cw_page_routes(): array
{
    return [
        'overview' => ['label' => 'Overview', 'route' => 'workbook.php', 'title' => 'Cost Workbook', 'description' => 'Review supplier costs, connect website products, and prepare reliable landed-cost decisions.'],
        'purchases' => ['label' => 'Purchases & Invoices', 'route' => 'purchases.php', 'title' => 'Purchases & Invoices', 'description' => 'Upload, review and approve supplier invoices for the selected period.'],
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-pages.css?v=1">
    <main class="workspace cw cw-page cost-workbook-page" id="costWorkbook" data-page="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" data-api="cw-api.php" data-phase2-api="cw-phase2-api.php" data-csrf="<?= htmlspecialchars(cw_csrf_token(), ENT_QUOTES, 'UTF-8') ?>" data-year="<?= (int) $period['year'] ?>" data-month="<?= (int) $period['month'] ?>">
      <header class="cw-hero"><div><p class="cw-eyebrow">Hambelela Organic · <?= htmlspecialchars($period['id'], ENT_QUOTES, 'UTF-8') ?></p><h1><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></h1><p><?= htmlspecialchars($page['description'], ENT_QUOTES, 'UTF-8') ?></p></div><?php if($key==='purchases'): ?><div class="cw-hero-actions"><button class="cw-btn cw-primary" data-open-upload>Upload invoice</button></div><?php endif; ?></header>
      <?php if ($bootError): ?><div class="cw-alert cw-error" role="alert"><strong>Cost Workbook setup needs attention.</strong> The workbook could not be prepared safely.</div><?php endif; ?>
      <div id="cwNotice" class="cw-alert" hidden></div>
      <nav class="cw-period-nav" aria-label="Cost Workbook period"><a href="<?= cw_page_url($page['route'], ['year'=>(int)(new DateTimeImmutable($period['id'].'-01'))->modify('-1 month')->format('Y'),'month'=>(int)(new DateTimeImmutable($period['id'].'-01'))->modify('-1 month')->format('n')]) ?>" aria-label="Previous month">←</a><?php for($m=1;$m<=12;$m++): ?><a class="<?= $m===$period['month']?'is-active':'' ?>" <?= $m===$period['month']?'aria-current="date"':'' ?> href="<?= cw_page_url($page['route'], ['year'=>$period['year'],'month'=>$m]) ?>"><?= htmlspecialchars(DateTimeImmutable::createFromFormat('!m',(string)$m)->format('M'),ENT_QUOTES,'UTF-8') ?></a><?php endfor; ?><a href="<?= cw_page_url($page['route'], ['year'=>(int)(new DateTimeImmutable($period['id'].'-01'))->modify('+1 month')->format('Y'),'month'=>(int)(new DateTimeImmutable($period['id'].'-01'))->modify('+1 month')->format('n')]) ?>" aria-label="Next month">→</a></nav>
      <nav class="cw-steps" aria-label="Workbook sections"><?php foreach($routes as $routeKey=>$item): ?><a class="cw-section-link <?= $routeKey===$key?'is-active':'' ?>" <?= $routeKey===$key?'aria-current="page"':'' ?> href="<?= cw_page_url($item['route'],$period) ?>"><?= htmlspecialchars($item['label'],ENT_QUOTES,'UTF-8') ?></a><?php endforeach; ?></nav>
    <?php
}

function cw_page_end(array $scripts = []): void
{
    echo '</main>';
    foreach ($scripts as $script) echo '<script src="' . htmlspecialchars(BASE_URL . '/assets/js/' . $script, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    include BASE_PATH . '/shared/footer.php';
}
