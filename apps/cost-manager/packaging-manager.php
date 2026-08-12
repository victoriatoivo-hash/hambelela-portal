<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_login();

$pageTitle = 'Packaging Library | ' . APP_NAME;
$activeApp = 'cost-manager';
$items = [];
$types = [];
$error = null;
$message = $_SESSION['packaging_library_message'] ?? null;
unset($_SESSION['packaging_library_message']);

function packaging_manager_column_exists(string $column): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "packaging"
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$column]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function packaging_manager_table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function packaging_manager_try_sql(string $sql): void
{
    try {
        db()->exec($sql);
    } catch (Throwable $e) {
        // The page can still work in read-only hosting modes; surface save errors only when an action needs them.
    }
}

function packaging_manager_bootstrap(): void
{
    if (!packaging_manager_column_exists('category')) {
        packaging_manager_try_sql('ALTER TABLE packaging ADD COLUMN category VARCHAR(80) NULL');
    }
    if (!packaging_manager_column_exists('notes')) {
        packaging_manager_try_sql('ALTER TABLE packaging ADD COLUMN notes TEXT NULL');
    }
    if (!packaging_manager_column_exists('stock_left')) {
        packaging_manager_try_sql('ALTER TABLE packaging ADD COLUMN stock_left DECIMAL(12,3) NOT NULL DEFAULT 1');
    }
    if (!packaging_manager_column_exists('status')) {
        packaging_manager_try_sql("ALTER TABLE packaging ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");
    }
}

function packaging_manager_supplier_id(string $supplierName): ?int
{
    $supplierName = trim($supplierName);
    if ($supplierName === '' || !packaging_manager_table_exists('suppliers')) {
        return null;
    }

    $stmt = db()->prepare('SELECT id FROM suppliers WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$supplierName]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    try {
        $stmt = db()->prepare('INSERT INTO suppliers (name, created_at) VALUES (?, NOW())');
        $stmt->execute([$supplierName]);
    } catch (Throwable $e) {
        $stmt = db()->prepare('INSERT INTO suppliers (name) VALUES (?)');
        $stmt->execute([$supplierName]);
    }

    return (int) db()->lastInsertId();
}

function packaging_manager_save(array $data, ?int $id = null): void
{
    $name = trim((string) ($data['name'] ?? ''));
    $type = trim((string) ($data['type'] ?? ''));
    $size = trim((string) ($data['size'] ?? ''));
    $unitCost = (float) str_replace(',', '', (string) ($data['unit_cost'] ?? 0));
    $supplierId = packaging_manager_supplier_id((string) ($data['supplier'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));
    $status = strtolower(trim((string) ($data['status'] ?? 'active'))) === 'inactive' ? 'inactive' : 'active';

    if ($name === '' || $type === '' || $size === '' || $unitCost < 0) {
        throw new RuntimeException('Packaging name, type, size and unit cost are required.');
    }

    if ($id) {
        $stmt = db()->prepare(
            'UPDATE packaging
             SET name = ?, category = ?, unit = ?, unit_cost = ?, total_cost = ?, supplier_id = ?, notes = ?, status = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, $type, $size, $unitCost, $unitCost, $supplierId, $notes, $status, $id]);
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO packaging
            (name, category, quantity, stock_left, unit, unit_cost, total_cost, supplier_id, notes, status, created_at)
         VALUES
            (?, ?, 1, 1, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$name, $type, $size, $unitCost, $unitCost, $supplierId, $notes, $status]);
}

function packaging_manager_usage_count(int $id): int
{
    if (!packaging_manager_table_exists('product_packaging_components')) {
        return 0;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM product_packaging_components WHERE packaging_id = ?');
    $stmt->execute([$id]);

    return (int) $stmt->fetchColumn();
}

function packaging_manager_redirect(): void
{
    header('Location: packaging-manager.php');
    exit;
}

packaging_manager_bootstrap();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_packaging') {
            $id = isset($_POST['id']) && (int) $_POST['id'] > 0 ? (int) $_POST['id'] : null;
            packaging_manager_save($_POST, $id);
            $_SESSION['packaging_library_message'] = $id ? 'Packaging entry updated.' : 'Packaging entry added.';
            packaging_manager_redirect();
        }

        if ($action === 'toggle_status') {
            $id = (int) ($_POST['id'] ?? 0);
            $status = strtolower((string) ($_POST['status'] ?? 'active')) === 'inactive' ? 'inactive' : 'active';
            $stmt = db()->prepare('UPDATE packaging SET status = ? WHERE id = ?');
            $stmt->execute([$status, $id]);
            $_SESSION['packaging_library_message'] = 'Packaging status updated.';
            packaging_manager_redirect();
        }

        if ($action === 'delete_packaging') {
            $id = (int) ($_POST['id'] ?? 0);
            if (packaging_manager_usage_count($id) > 0) {
                throw new RuntimeException('This packaging item is linked to product variations, so it cannot be deleted. Mark it inactive instead.');
            }
            $stmt = db()->prepare('DELETE FROM packaging WHERE id = ?');
            $stmt->execute([$id]);
            $_SESSION['packaging_library_message'] = 'Packaging entry deleted.';
            packaging_manager_redirect();
        }

        if ($action === 'import_csv') {
            if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                throw new RuntimeException('Please choose a CSV file to import.');
            }
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'rb');
            if (!$handle) {
                throw new RuntimeException('Could not read the uploaded CSV file.');
            }
            $saved = 0;
            $skipped = 0;
            $rowNo = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNo++;
                if ($rowNo === 1 && stripos((string) ($row[0] ?? ''), 'packaging') !== false) {
                    continue;
                }
                $payload = [
                    'name' => $row[0] ?? '',
                    'type' => $row[1] ?? '',
                    'size' => $row[2] ?? '',
                    'unit_cost' => $row[3] ?? '',
                    'supplier' => $row[4] ?? '',
                    'notes' => $row[5] ?? '',
                    'status' => 'active',
                ];
                try {
                    packaging_manager_save($payload);
                    $saved++;
                } catch (Throwable $e) {
                    $skipped++;
                }
            }
            fclose($handle);
            $_SESSION['packaging_library_message'] = "{$saved} packaging entries imported. {$skipped} row(s) skipped.";
            packaging_manager_redirect();
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $hasProductLinks = packaging_manager_table_exists('product_packaging_components') && packaging_manager_table_exists('finished_products');
    $usageSelect = $hasProductLinks ? "GROUP_CONCAT(DISTINCT fp.name ORDER BY fp.name SEPARATOR ', ') AS used_in_products" : "NULL AS used_in_products";
    $usageJoins = $hasProductLinks
        ? 'LEFT JOIN product_packaging_components ppc ON ppc.packaging_id = p.id
         LEFT JOIN finished_products fp ON fp.id = ppc.product_id'
        : '';

    $types = db()->query("SELECT DISTINCT COALESCE(NULLIF(category, ''), 'Other') AS category FROM packaging ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

    $where = [];
    $params = [];
    $q = trim((string) ($_GET['q'] ?? ''));
    $type = trim((string) ($_GET['type'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));

    if ($q !== '') {
        $where[] = '(p.name LIKE ? OR p.unit LIKE ? OR p.notes LIKE ? OR s.name LIKE ?)';
        $needle = '%' . $q . '%';
        array_push($params, $needle, $needle, $needle, $needle);
    }
    if ($type !== '') {
        $where[] = 'COALESCE(NULLIF(p.category, \'\'), \'Other\') = ?';
        $params[] = $type;
    }
    if ($status !== '') {
        $where[] = 'COALESCE(NULLIF(p.status, \'\'), \'active\') = ?';
        $params[] = $status;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare(
        "SELECT
            p.id,
            p.name,
            COALESCE(NULLIF(p.category, ''), 'Other') AS category,
            p.quantity,
            p.stock_left,
            p.unit,
            p.unit_cost,
            p.total_cost,
            p.notes,
            COALESCE(NULLIF(p.status, ''), 'active') AS status,
            p.created_at,
            COALESCE(s.name, 'Unknown supplier') AS supplier_name,
            {$usageSelect}
         FROM packaging p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         {$usageJoins}
         {$whereSql}
         GROUP BY p.id, p.name, p.category, p.quantity, p.stock_left, p.unit, p.unit_cost, p.total_cost, p.notes, p.status, p.created_at, s.name
         ORDER BY FIELD(COALESCE(NULLIF(p.status, ''), 'active'), 'active', 'inactive'), p.name ASC
         LIMIT 500"
    );
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?: $e->getMessage();
}

$activeItems = array_values(array_filter($items, static fn (array $item): bool => (string) $item['status'] === 'active'));
$activeCosts = array_map(static fn (array $item): float => (float) $item['unit_cost'], $activeItems);
$lowestCost = $activeCosts ? min($activeCosts) : 0;
$highestCost = $activeCosts ? max($activeCosts) : 0;

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module packaging-library-page">
    <a class="button back-link" href="workbook.php"><i data-lucide="arrow-left"></i> Back to Cost Workbook</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Packaging Library</p>
            <h1>Packaging Library</h1>
            <p>Reusable packaging materials that feed Step 5 Product & SKU cost calculations.</p>
        </div>
        <a class="button primary" href="upload-invoice.php?mode=packaging"><i data-lucide="file-up"></i> Upload packaging invoice</a>
    </section>

    <?php if ($message): ?><section class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></section><?php endif; ?>
    <?php if ($error): ?><section class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></section><?php endif; ?>

    <section class="metric-grid order-kpi-grid">
        <article class="metric"><span>Active entries</span><strong><?= number_format(count($activeItems)) ?></strong><small>Total packaging entries active</small></article>
        <article class="metric"><span>Lowest unit cost</span><strong>N$ <?= number_format($lowestCost, 4) ?></strong><small>Lowest active packaging cost</small></article>
        <article class="metric"><span>Highest unit cost</span><strong>N$ <?= number_format($highestCost, 4) ?></strong><small>Highest active packaging cost</small></article>
    </section>

    <section class="panel packaging-library-actions">
        <div class="section-row">
            <div>
                <p class="eyebrow">Manage library</p>
                <h2>Add or import packaging</h2>
            </div>
            <span class="status">CSV order: Packaging Name, Packaging Type, Size, Unit Cost, Supplier, Notes</span>
        </div>
        <form class="packaging-form-grid" method="post">
            <input type="hidden" name="action" value="save_packaging">
            <label>Packaging Name<input name="name" required placeholder="Amber Glass Bottle 500ml"></label>
            <label>Packaging Type<input name="type" required placeholder="Bottle / Pouch / Jar"></label>
            <label>Size / Capacity<input name="size" required placeholder="500ml, 1kg, 250g"></label>
            <label>Unit Cost (N$)<input name="unit_cost" type="number" step="0.0001" min="0" required placeholder="0.00"></label>
            <label>Supplier<input name="supplier" placeholder="Supplier name"></label>
            <label>Status<select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            <label class="full">Notes<textarea name="notes" rows="2" placeholder="Includes cap, label not included, etc."></textarea></label>
            <button class="button primary" type="submit"><i data-lucide="plus"></i> Add packaging entry</button>
        </form>
        <form class="csv-import-box" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_csv">
            <label>Import CSV<input name="csv_file" type="file" accept=".csv,text/csv" required></label>
            <button class="button" type="submit"><i data-lucide="upload"></i> Import CSV</button>
        </form>
    </section>

    <section class="panel">
        <div class="section-row">
            <div>
                <p class="eyebrow">Reference table</p>
                <h2>Packaging entries</h2>
            </div>
            <span class="status"><?= number_format(count($items)) ?> shown</span>
        </div>
        <form class="filters-row packaging-filters" method="get">
            <label>Search<input name="q" value="<?= htmlspecialchars((string) ($_GET['q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Search name, size, supplier"></label>
            <label>Type<select name="type"><option value="">All types</option><?php foreach ($types as $typeOption): ?><option value="<?= htmlspecialchars((string) $typeOption, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($_GET['type'] ?? '') === (string) $typeOption ? 'selected' : '' ?>><?= htmlspecialchars((string) $typeOption, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
            <label>Status<select name="status"><option value="">All statuses</option><option value="active" <?= (string) ($_GET['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= (string) ($_GET['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
            <button class="button" type="submit"><i data-lucide="search"></i> Filter</button>
        </form>

        <?php if (!$items): ?>
            <div class="empty-state packaging-empty">
                <strong>No packaging added yet.</strong>
                <p>Add your first packaging type or import via CSV to get started.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll packaging-library-table">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Packaging Name</th>
                            <th>Type</th>
                            <th>Size / Capacity</th>
                            <th>Unit Cost</th>
                            <th>Supplier</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th>Used In Products</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $itemId = (int) $item['id'];
                            $saveFormId = 'save-packaging-' . $itemId;
                            $usedIn = trim((string) ($item['used_in_products'] ?? ''));
                            ?>
                            <tr>
                                <td><input form="<?= htmlspecialchars($saveFormId, ENT_QUOTES, 'UTF-8') ?>" name="name" value="<?= htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8') ?>" required></td>
                                <td><input form="<?= htmlspecialchars($saveFormId, ENT_QUOTES, 'UTF-8') ?>" name="type" value="<?= htmlspecialchars((string) $item['category'], ENT_QUOTES, 'UTF-8') ?>" required></td>
                                <td><input form="<?= htmlspecialchars($saveFormId, ENT_QUOTES, 'UTF-8') ?>" name="size" value="<?= htmlspecialchars((string) $item['unit'], ENT_QUOTES, 'UTF-8') ?>" required></td>
                                <td><input form="<?= htmlspecialchars($saveFormId, ENT_QUOTES, 'UTF-8') ?>" name="unit_cost" type="number" step="0.0001" min="0" value="<?= htmlspecialchars((string) $item['unit_cost'], ENT_QUOTES, 'UTF-8') ?>" required></td>
                                <td><input form="<?= htmlspecialchars($saveFormId, ENT_QUOTES, 'UTF-8') ?>" name="supplier" value="<?= htmlspecialchars((string) $item['supplier_name'], ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><textarea form="<?= htmlspecialchars($saveFormId, ENT_QUOTES, 'UTF-8') ?>" name="notes" rows="1"><?= htmlspecialchars((string) ($item['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></td>
                                <td>
                                    <select form="<?= htmlspecialchars($saveFormId, ENT_QUOTES, 'UTF-8') ?>" name="status">
                                        <option value="active" <?= (string) $item['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= (string) $item['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </td>
                                <td><?= htmlspecialchars($usedIn !== '' ? $usedIn : 'Not linked yet', ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="packaging-row-actions">
                                    <form id="<?= htmlspecialchars($saveFormId, ENT_QUOTES, 'UTF-8') ?>" method="post" class="inline-form">
                                        <input type="hidden" name="action" value="save_packaging">
                                        <input type="hidden" name="id" value="<?= $itemId ?>">
                                    </form>
                                    <button class="button" type="submit" form="<?= htmlspecialchars($saveFormId, ENT_QUOTES, 'UTF-8') ?>">Save</button>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this packaging entry? This is blocked if it is linked to a product variation.');">
                                    <input type="hidden" name="action" value="delete_packaging">
                                    <input type="hidden" name="id" value="<?= $itemId ?>">
                                    <button class="button danger" type="submit" <?= $usedIn !== '' ? 'disabled title="Linked packaging cannot be deleted; mark it inactive instead."' : '' ?>>Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<style>
.packaging-library-page { max-width:1500px; }
.notice { border-radius:12px; padding:12px 14px; margin:0 0 14px; font-weight:800; }
.notice.success { background:#e6f8ef; color:#087344; border:1px solid #b8ebcf; }
.notice.error { background:#fff0f3; color:#b4234a; border:1px solid #ffd0dc; }
.packaging-library-actions { display:grid; gap:16px; }
.packaging-form-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
.packaging-form-grid label,
.csv-import-box label,
.packaging-filters label { display:grid; gap:6px; font-size:12px; font-weight:900; color:#3e3a40; }
.packaging-form-grid input,
.packaging-form-grid select,
.packaging-form-grid textarea,
.csv-import-box input,
.packaging-filters input,
.packaging-filters select,
.packaging-library-table input,
.packaging-library-table select,
.packaging-library-table textarea { width:100%; border:1px solid #d8dce8; border-radius:9px; padding:9px 10px; background:#fff; font:inherit; }
.packaging-form-grid .full { grid-column:1 / -1; }
.csv-import-box { display:flex; align-items:end; gap:12px; flex-wrap:wrap; border-top:1px solid rgba(0,0,0,.08); padding-top:14px; }
.packaging-filters { margin-bottom:14px; align-items:end; }
.packaging-library-table table { min-width:1180px; }
.packaging-library-table td { vertical-align:middle; }
.packaging-row-actions { display:flex; gap:8px; align-items:center; }
.packaging-row-actions form { margin:0; }
.button.danger { border-color:#ffd0dc; color:#b4234a; background:#fff5f7; }
.button.danger:disabled { opacity:.45; cursor:not-allowed; }
.packaging-empty { border:1px dashed #ffd0df; border-radius:16px; padding:28px; background:#fff7fb; }
@media (max-width:900px) {
    .packaging-form-grid { grid-template-columns:1fr; }
    .csv-import-box { display:grid; }
}
</style>
<?php include BASE_PATH . '/shared/footer.php'; ?>
