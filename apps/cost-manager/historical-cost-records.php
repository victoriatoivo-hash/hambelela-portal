<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/cost-workbook-history.php';

require_role('owner_admin');
cw_history_require_read_only_request();

$datasets = [
    'supplier_invoices' => ['label' => 'Supplier invoices', 'order' => 'id', 'search' => ['invoice_number']],
    'raw_materials' => ['label' => 'Raw materials', 'order' => 'id', 'search' => ['name', 'category', 'unit']],
    'transport_invoices' => ['label' => 'Transport invoices', 'order' => 'id', 'search' => ['invoice_number', 'reference', 'waybill_number', 'consignment_number', 'route', 'status']],
    'transport_allocations' => ['label' => 'Transport allocations', 'order' => 'id', 'search' => ['component_type', 'batch_reference', 'allocation_basis']],
    'ingredient_costs_master' => ['label' => 'Ingredient costs', 'order' => 'component_id', 'search' => ['supplier_name', 'ingredient_name', 'unit']],
    'packaging_costs_master' => ['label' => 'Packaging costs', 'order' => 'component_id', 'search' => ['supplier_name', 'packaging_name', 'unit']],
    'finished_products' => ['label' => 'Finished products', 'order' => 'id', 'search' => ['name', 'sku', 'costing_type']],
    'product_recipes' => ['label' => 'Product recipes', 'order' => 'id', 'search' => ['version']],
    'woo_sales' => ['label' => 'WooCommerce sales history', 'order' => 'id', 'search' => ['product_name']],
];
$sensitiveColumns = ['pdf_path', 'file_path', 'stored_file', 'consumer_key', 'consumer_secret', 'access_token', 'refresh_token'];
$selected = (string) ($_GET['dataset'] ?? 'supplier_invoices');
if (!isset($datasets[$selected])) {
    $selected = 'supplier_invoices';
}
$search = trim((string) ($_GET['search'] ?? ''));
if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}
$direction = strtolower((string) ($_GET['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$page = max(1, min(1000000, (int) ($_GET['page'] ?? 1)));
$perPage = 50;
$rows = [];
$columns = [];
$total = 0;
$error = null;

try {
    $pdo = db();
    $tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $tableCheck->execute([$selected]);
    if ((int) $tableCheck->fetchColumn() === 1) {
        $schemaRows = $pdo->query('SHOW COLUMNS FROM `' . $selected . '`')->fetchAll(PDO::FETCH_ASSOC);
        $actualColumns = array_column($schemaRows, 'Field');
        $columns = array_values(array_filter($actualColumns, static function (string $column) use ($sensitiveColumns): bool {
            $normalized = strtolower($column);
            return !in_array($normalized, $sensitiveColumns, true)
                && !preg_match('/password|secret|token|credential/', $normalized);
        }));
        $searchColumns = array_values(array_intersect($datasets[$selected]['search'], $actualColumns));
        $where = '';
        $params = [];
        if ($search !== '' && $searchColumns) {
            $where = ' WHERE ' . implode(' OR ', array_map(static fn (string $column): string => '`' . $column . '` LIKE ?', $searchColumns));
            $params = array_fill(0, count($searchColumns), '%' . $search . '%');
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM `' . $selected . '`' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $maxPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $maxPage);
        $offset = ($page - 1) * $perPage;
        $statement = $pdo->prepare('SELECT * FROM `' . $selected . '`' . $where . ' ORDER BY `' . $datasets[$selected]['order'] . '` ' . $direction . ' LIMIT ' . $perPage . ' OFFSET ' . $offset);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $exception) {
    error_log('Historical Cost Records read failed: ' . get_class($exception));
    $error = 'Historical records could not be loaded safely.';
}

function cw_history_value(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return is_scalar($value) ? (string) $value : '[structured value]';
}

function cw_history_query(array $changes = []): string
{
    $query = array_merge([
        'dataset' => (string) ($_GET['dataset'] ?? 'supplier_invoices'),
        'search' => trim((string) ($_GET['search'] ?? '')),
        'direction' => strtolower((string) ($_GET['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        'page' => max(1, (int) ($_GET['page'] ?? 1)),
    ], $changes);
    return '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

$pageTitle = 'Historical Cost Records | ' . APP_NAME;
$activeApp = 'cost-manager';
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cost-workbook-history.css?v=1">
<main class="workspace cw-history-page" id="historicalCostRecords">
    <header class="cw-history-header">
        <div><p class="cw-history-eyebrow">Previous Landing Cost system · Read-only archive</p><h1>Historical Cost Records</h1><p>These records were created in the previous Landing Cost system. They are preserved for reference only and are not part of the current Cost Workbook. Historical records cannot be edited, recalculated, approved, synchronized or published.</p></div>
        <div class="cw-history-header-actions"><span class="cw-history-readonly-badge">Read only</span><a class="cw-history-button" href="<?= BASE_URL ?>/apps/cost-manager/workbook.php">Return to Cost Workbook</a></div>
    </header>
    <form class="cw-history-filters" method="get">
        <label>Record type<select name="dataset"><?php foreach ($datasets as $value => $config): ?><option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= $selected === $value ? ' selected' : '' ?>><?= htmlspecialchars($config['label'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
        <label>Search<input name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" placeholder="Search this historical section"></label>
        <label>Order<select name="direction"><option value="desc"<?= $direction === 'DESC' ? ' selected' : '' ?>>Newest first</option><option value="asc"<?= $direction === 'ASC' ? ' selected' : '' ?>>Oldest first</option></select></label>
        <button type="submit">View records</button>
    </form>
    <?php if ($error): ?><div class="cw-history-notice" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php else: ?>
    <section class="cw-history-panel" aria-labelledby="historyTitle">
        <div class="cw-history-panel-heading"><div><h2 id="historyTitle"><?= htmlspecialchars($datasets[$selected]['label'], ENT_QUOTES, 'UTF-8') ?></h2><p><?= number_format($total) ?> preserved historical record<?= $total === 1 ? '' : 's' ?></p></div><span class="cw-history-readonly-badge">Historical</span></div>
        <div class="cw-history-table-wrap"><?php if (!$rows): ?><p class="cw-history-empty">No matching historical records.</p><?php else: ?><table><thead><tr><?php foreach ($columns as $column): ?><th><?= htmlspecialchars(str_replace('_', ' ', $column), ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($columns as $column): ?><td><?= htmlspecialchars(cw_history_value($row[$column] ?? null), ENT_QUOTES, 'UTF-8') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
        <?php if ($total > $perPage): ?><nav class="cw-history-pagination" aria-label="Historical record pages"><?php if ($page > 1): ?><a href="<?= htmlspecialchars(cw_history_query(['page' => $page - 1]), ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php endif; ?><span>Page <?= number_format($page) ?> of <?= number_format((int) ceil($total / $perPage)) ?></span><?php if ($page * $perPage < $total): ?><a href="<?= htmlspecialchars(cw_history_query(['page' => $page + 1]), ENT_QUOTES, 'UTF-8') ?>">Next</a><?php endif; ?></nav><?php endif; ?>
    </section><?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
