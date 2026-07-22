<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/woocommerce.php';

require_login();

$pageTitle = 'Customer Orders Report | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();

function cor_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cor_money($amount): string
{
    return 'N$' . number_format((float) $amount, 2);
}

function cor_pct($value): string
{
    return number_format((float) $value, 1) . '%';
}

function cor_short_text($value, int $limit = 30): string
{
    $text = trim((string) $value);
    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, max(0, $limit - 3))) . '...';
}

function cor_slug($value): string
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'other';

    return trim($value, '-');
}

function cor_contains(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

function cor_report_log(string $message, array $context = []): void
{
    $dir = BASE_PATH . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }

    @file_put_contents($dir . '/customer-orders-report.log', $line . PHP_EOL, FILE_APPEND);
}

function cor_order_status_label(string $status): string
{
    return OPS_ORDER_STATUSES[$status] ?? ucwords(str_replace(['_', '-'], ' ', $status));
}

function cor_period_dates(string $range, string $from, string $to): array
{
    $today = new DateTimeImmutable('today');
    if ($range === 'today') {
        return [$today->format('Y-m-d'), $today->format('Y-m-d')];
    }
    if ($range === 'week') {
        return [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')];
    }
    if ($range === 'year') {
        return [$today->format('Y-01-01'), $today->format('Y-m-d')];
    }
    if ($range === 'custom') {
        $fromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $today->modify('first day of this month')->format('Y-m-d');
        $toDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : $today->format('Y-m-d');
        return [$fromDate, $toDate];
    }

    return [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')];
}

function cor_filtered_where(array $filters, array &$params, string $alias = 'o'): string
{
    $where = ["DATE({$alias}.created_at) BETWEEN ? AND ?"];
    $params[] = $filters['from'];
    $params[] = $filters['to'];

    if ($filters['status'] !== 'all') {
        $where[] = "{$alias}.status = ?";
        $params[] = $filters['status'];
    }
    if ($filters['mode'] !== 'all') {
        $where[] = "COALESCE(NULLIF({$alias}.fulfilment_mode, ''), {$alias}.order_type) = ?";
        $params[] = $filters['mode'];
    }
    if ($filters['payment'] !== 'all') {
        $where[] = "{$alias}.payment_method LIKE ?";
        $params[] = '%' . $filters['payment'] . '%';
    }

    return implode(' AND ', $where);
}

function cor_query_rows(string $sql, array $params = []): array
{
    return ops_rows($sql, $params);
}

function cor_query_one(string $sql, array $params = []): array
{
    $rows = cor_query_rows($sql, $params);

    return $rows[0] ?? [];
}

function cor_woo_meta(array $item, string $key, $fallback = '')
{
    foreach (($item['meta_data'] ?? []) as $meta) {
        if (($meta['key'] ?? '') === $key) {
            return $meta['value'] ?? $fallback;
        }
    }

    return $fallback;
}

function cor_inventory_variant_label(array $variation): string
{
    $parts = [];
    foreach (($variation['attributes'] ?? []) as $attribute) {
        $value = trim((string) ($attribute['option'] ?? $attribute['value'] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    return implode(' / ', $parts);
}

function cor_inventory_format_variant_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = str_replace(['pa_', '-', '_'], ['', ' ', ' '], $value);

    return ucwords(trim($value));
}

function cor_store_catalog_base(): string
{
    return 'https://hambelelaorganic.com';
}

function cor_store_get(string $path, array $query = []): array
{
    $url = cor_store_catalog_base() . '/wp-json/wc/store/v1/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $body = '';
    $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Hambelela Portal Customer Orders Report',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    }

    if ((!is_string($body) || $body === '' || $status >= 400) && ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: Hambelela Portal Customer Orders Report\r\n",
                'timeout' => 20,
            ],
        ]);
        $streamBody = @file_get_contents($url, false, $context);
        if (is_string($streamBody) && $streamBody !== '') {
            $body = $streamBody;
            $status = 200;
        }
    }

    if (!is_string($body) || $body === '' || $status >= 400) {
        return [];
    }

    $data = json_decode($body, true);

    return is_array($data) ? $data : [];
}

function cor_store_price(array $item): float
{
    $prices = $item['prices'] ?? [];
    if (!is_array($prices)) {
        return 0.0;
    }

    $raw = $prices['price'] ?? $prices['regular_price'] ?? $prices['sale_price'] ?? 0;
    $minorUnit = (int) ($prices['currency_minor_unit'] ?? 2);

    return (float) $raw / (10 ** max(0, $minorUnit));
}

function cor_store_stock_qty(array $item): float
{
    $lowRemaining = $item['low_stock_remaining'] ?? null;
    if (is_numeric($lowRemaining)) {
        return (float) $lowRemaining;
    }

    if (($item['is_in_stock'] ?? false) || (($item['stock_availability']['class'] ?? '') === 'in-stock')) {
        return 1.0;
    }

    return 0.0;
}

function cor_inventory_row_from_store_product(array $product, ?array $variation = null): array
{
    $source = $variation ?: $product;
    $hasStockState = array_key_exists('is_in_stock', $source) || isset($source['stock_availability']);
    $stockSource = $hasStockState ? $source : $product;
    $qty = cor_store_stock_qty($stockSource);
    $price = cor_store_price($source);
    if ($price <= 0 && $variation) {
        $price = cor_store_price($product);
    }
    $lowThreshold = 5;
    $categories = [];
    foreach (($product['categories'] ?? []) as $category) {
        $name = trim((string) ($category['name'] ?? ''));
        if ($name !== '') {
            $categories[] = $name;
        }
    }

    $isInStock = (bool) (($stockSource['is_in_stock'] ?? false) || (($stockSource['stock_availability']['class'] ?? '') === 'in-stock'));
    if (!$isInStock || $qty <= 0) {
        $stockClass = 'out';
    } elseif (($stockSource['low_stock_remaining'] ?? null) !== null && $qty <= $lowThreshold) {
        $stockClass = 'low';
    } else {
        $stockClass = 'in';
    }

    return [
        'id' => (int) ($source['id'] ?? $product['id'] ?? 0),
        'name' => (string) ($product['name'] ?? $source['name'] ?? 'Product'),
        'variant' => $variation ? cor_inventory_variant_label($variation) : '',
        'category' => implode(', ', $categories),
        'sku' => trim((string) ($source['sku'] ?? $product['sku'] ?? '')) ?: '-',
        'price' => $price,
        'regular_price' => $price,
        'sale_price' => 0.0,
        'cost' => 0.0,
        'qty' => $qty,
        'cost_val' => 0.0,
        'retail_val' => $qty * $price,
        'profit' => $qty * $price,
        'stock_class' => $stockClass,
        'low_threshold' => $lowThreshold,
    ];
}

function cor_wp_config_path(): ?string
{
    $candidates = array_unique(array_filter([
        BASE_PATH . '/../public_html/wp-config.php',
        dirname(BASE_PATH) . '/public_html/wp-config.php',
        dirname(BASE_PATH, 2) . '/public_html/wp-config.php',
        '/home/hambele1/public_html/wp-config.php',
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/wp-config.php',
    ]));

    foreach ($candidates as $path) {
        $real = realpath($path);
        if ($real && is_file($real) && is_readable($real)) {
            return $real;
        }
    }

    return null;
}

function cor_wp_config_define(string $config, string $name): string
{
    if (preg_match("/define\\(\\s*['\"]" . preg_quote($name, '/') . "['\"]\\s*,\\s*['\"]([^'\"]*)['\"]\\s*\\)/", $config, $matches)) {
        return $matches[1];
    }

    return '';
}

function cor_wp_connection(): ?array
{
    static $connection = null;
    static $checked = false;
    if ($checked) {
        return $connection;
    }
    $checked = true;

    $path = cor_wp_config_path();
    if (!$path) {
        return null;
    }

    $config = (string) @file_get_contents($path);
    $dbName = cor_wp_config_define($config, 'DB_NAME');
    $dbUser = cor_wp_config_define($config, 'DB_USER');
    $dbPass = cor_wp_config_define($config, 'DB_PASSWORD');
    $dbHost = cor_wp_config_define($config, 'DB_HOST') ?: 'localhost';
    $charset = cor_wp_config_define($config, 'DB_CHARSET') ?: 'utf8mb4';
    $prefix = 'wp_';
    if (preg_match('/\\$table_prefix\\s*=\\s*[\'"]([^\'"]+)[\'"]\\s*;/', $config, $matches)) {
        $prefix = $matches[1];
    }

    if ($dbName === '' || $dbUser === '') {
        return null;
    }

    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset={$charset}",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        $connection = ['pdo' => $pdo, 'prefix' => preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?: 'wp_'];
    } catch (Throwable $e) {
        $connection = null;
    }

    return $connection;
}

function cor_inventory_rows_from_wp_db(): array
{
    $connection = cor_wp_connection();
    if (!$connection) {
        return [];
    }

    /** @var PDO $pdo */
    $pdo = $connection['pdo'];
    $prefix = $connection['prefix'];
    $posts = $prefix . 'posts';
    $postmeta = $prefix . 'postmeta';
    $termRelationships = $prefix . 'term_relationships';
    $termTaxonomy = $prefix . 'term_taxonomy';
    $terms = $prefix . 'terms';

    $sql = "
        SELECT
            p.ID,
            p.post_title AS product_name,
            p.post_parent,
            p.post_type,
            parent.post_title AS parent_name,
            MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) AS sku,
            MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) AS regular_price,
            MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) AS sale_price,
            MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) AS price,
            MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS qty,
            MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) AS stock_status,
            MAX(CASE WHEN pm.meta_key = '_wc_cog_cost' THEN pm.meta_value END) AS cost,
            MAX(CASE WHEN pm.meta_key = '_low_stock_amount' THEN pm.meta_value END) AS low_stock_threshold,
            GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS categories
        FROM {$posts} p
        LEFT JOIN {$posts} parent ON parent.ID = p.post_parent
        LEFT JOIN {$postmeta} pm ON pm.post_id = p.ID
        LEFT JOIN {$termRelationships} tr ON tr.object_id = CASE WHEN p.post_parent > 0 THEN p.post_parent ELSE p.ID END
        LEFT JOIN {$termTaxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
        LEFT JOIN {$terms} t ON t.term_id = tt.term_id
        WHERE p.post_type IN ('product', 'product_variation')
          AND p.post_status IN ('publish', 'private')
        GROUP BY p.ID
        ORDER BY CASE WHEN p.post_parent > 0 THEN p.post_parent ELSE p.ID END ASC, p.post_type DESC, p.post_title ASC";

    try {
        $raw = $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $attributeStmt = $pdo->prepare("SELECT meta_value FROM {$postmeta} WHERE post_id = ? AND meta_key LIKE 'attribute_%' ORDER BY meta_id ASC LIMIT 5");
    $parentsWithVariations = [];
    foreach ($raw as $item) {
        if ((string) ($item['post_type'] ?? '') === 'product_variation' && (int) ($item['post_parent'] ?? 0) > 0) {
            $parentsWithVariations[(int) $item['post_parent']] = true;
        }
    }

    $rows = [];
    foreach ($raw as $item) {
        if ((string) ($item['post_type'] ?? '') === 'product' && isset($parentsWithVariations[(int) $item['ID']])) {
            continue;
        }
        $qtyRaw = $item['qty'];
        $stockStatus = (string) ($item['stock_status'] ?? '');
        $qty = is_numeric($qtyRaw) ? (float) $qtyRaw : ($stockStatus === 'instock' ? 1.0 : 0.0);
        $regularPrice = is_numeric($item['regular_price']) ? (float) $item['regular_price'] : (is_numeric($item['price']) ? (float) $item['price'] : 0.0);
        $salePrice = is_numeric($item['sale_price']) ? (float) $item['sale_price'] : 0.0;
        $price = is_numeric($item['price']) ? (float) $item['price'] : ($salePrice > 0 ? $salePrice : $regularPrice);
        $cost = is_numeric($item['cost']) ? (float) $item['cost'] : 0.0;
        $lowThreshold = is_numeric($item['low_stock_threshold']) && (int) $item['low_stock_threshold'] > 0 ? (int) $item['low_stock_threshold'] : 5;
        if ($stockStatus === 'outofstock' || $qty <= 0) {
            $stockClass = 'out';
        } elseif ($qty <= $lowThreshold) {
            $stockClass = 'low';
        } else {
            $stockClass = 'in';
        }

        $variant = '';
        if ((string) $item['post_type'] === 'product_variation') {
            try {
                $attributeStmt->execute([(int) $item['ID']]);
                $variant = implode(' / ', array_filter(array_map(
                    static fn($value): string => cor_inventory_format_variant_value((string) $value),
                    $attributeStmt->fetchAll(PDO::FETCH_COLUMN) ?: []
                )));
                $attributeStmt->closeCursor();
            } catch (Throwable $e) {
                $variant = '';
            }
        }

        $rows[] = [
            'id' => (int) $item['ID'],
            'name' => (string) ($item['parent_name'] ?: $item['product_name'] ?: 'Product'),
            'variant' => $variant,
            'category' => (string) ($item['categories'] ?? ''),
            'sku' => trim((string) ($item['sku'] ?? '')) ?: '-',
            'price' => $price,
            'regular_price' => $regularPrice,
            'sale_price' => $salePrice,
            'cost' => $cost,
            'qty' => $qty,
            'cost_val' => $qty * $cost,
            'retail_val' => $qty * $price,
            'profit' => ($qty * $price) - ($qty * $cost),
            'stock_class' => $stockClass,
            'low_threshold' => $lowThreshold,
        ];
    }

    return $rows;
}

function cor_inventory_row(array $product, ?array $variation = null): array
{
    $source = $variation ?: $product;
    $parentCost = (float) cor_woo_meta($product, '_wc_cog_cost', 0);
    $cost = (float) cor_woo_meta($source, '_wc_cog_cost', $parentCost);
    $qty = (float) ($source['stock_quantity'] ?? 0);
    $price = (float) (($source['regular_price'] ?? '') !== '' ? $source['regular_price'] : ($source['price'] ?? 0));
    $regularPrice = (float) (($source['regular_price'] ?? '') !== '' ? $source['regular_price'] : $price);
    $salePrice = (float) (($source['sale_price'] ?? '') !== '' ? $source['sale_price'] : 0);
    $lowThreshold = (int) cor_woo_meta($source, '_low_stock_amount', cor_woo_meta($product, '_low_stock_amount', 5));
    if ($lowThreshold <= 0) {
        $lowThreshold = 5;
    }
    $categories = [];
    foreach (($product['categories'] ?? []) as $category) {
        $name = trim((string) ($category['name'] ?? ''));
        if ($name !== '') {
            $categories[] = $name;
        }
    }

    $stockClass = 'in';
    if ($qty <= 0) {
        $stockClass = 'out';
    } elseif ($qty <= $lowThreshold) {
        $stockClass = 'low';
    }

    return [
        'id' => (int) ($source['id'] ?? 0),
        'name' => (string) ($product['name'] ?? $source['name'] ?? 'Product'),
        'variant' => $variation ? cor_inventory_variant_label($variation) : '',
        'category' => implode(', ', $categories),
        'sku' => trim((string) ($source['sku'] ?? '')) ?: '-',
        'price' => $price,
        'regular_price' => $regularPrice,
        'sale_price' => $salePrice,
        'cost' => $cost,
        'qty' => $qty,
        'cost_val' => $qty * $cost,
        'retail_val' => $qty * $price,
        'profit' => ($qty * $price) - ($qty * $cost),
        'stock_class' => $stockClass,
        'low_threshold' => $lowThreshold,
    ];
}

function cor_fetch_inventory_result(): array
{
    $rows = [];
    $logs = [];
    $sortRows = static function (array $rows): array {
        usort($rows, static function (array $a, array $b): int {
            $name = strcmp(strtolower((string) $a['name']), strtolower((string) $b['name']));
            if ($name !== 0) {
                return $name;
            }

            return strcmp(strtolower((string) $a['variant']), strtolower((string) $b['variant']));
        });

        return $rows;
    };

    $wpRows = cor_inventory_rows_from_wp_db();
    if ($wpRows) {
        $logs[] = [
            'endpoint' => 'wp_posts/wp_postmeta',
            'status' => 'local',
            'count' => count($wpRows),
        ];
        cor_report_log('Inventory WordPress product rows fetched', end($logs) ?: []);

        return ['rows' => $sortRows($wpRows), 'source' => 'woocommerce_wp_db', 'error' => null, 'logs' => $logs];
    }

    if (!wc_configured()) {
        $error = 'WooCommerce API is not configured in config.local.php.';
        cor_report_log('Inventory WooCommerce fetch skipped', [
            'endpoint' => 'products',
            'error' => $error,
        ]);

        return ['rows' => [], 'source' => 'woocommerce_wp_db', 'error' => 'WordPress product database is unavailable and ' . $error, 'logs' => $logs];
    }

    try {
        for ($page = 1; $page <= 4; $page++) {
            $products = wc_get('products', [
                'status' => 'publish',
                'per_page' => 50,
                'page' => $page,
            ]);
            $logs[] = ['endpoint' => 'products', 'page' => $page, 'count' => count($products)];
            cor_report_log('Inventory WooCommerce products fetched', end($logs) ?: []);
            if (!$products) {
                break;
            }
            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $rows[] = cor_inventory_row($product);
                if (count($rows) >= 300) {
                    break 2;
                }
            }
            if (count($products) < 50) {
                break;
            }
        }
    } catch (Throwable $e) {
        cor_report_log('Inventory WooCommerce products failed', [
            'endpoint' => 'products',
            'error' => $e->getMessage(),
        ]);

        return ['rows' => [], 'source' => 'woocommerce_rest', 'error' => $e->getMessage(), 'logs' => $logs];
    }

    $rows = $sortRows($rows);
    $error = $rows ? null : 'WooCommerce returned 0 products. Check REST API permissions and product visibility.';
    if ($error) {
        cor_report_log('Inventory WooCommerce returned no products', ['endpoint' => 'products']);
    }

    return ['rows' => $rows, 'source' => 'woocommerce_rest', 'error' => $error, 'logs' => $logs];
}

function cor_fetch_inventory_rows(): array
{
    $result = cor_fetch_inventory_result();

    return $result['rows'];
}

function cor_save_inventory_cost(int $productId, float $cost): void
{
    if ($productId <= 0) {
        throw new RuntimeException('Missing product or variation id.');
    }
    if ($cost < 0) {
        throw new RuntimeException('Cost cannot be negative.');
    }

    $connection = cor_wp_connection();
    if (!$connection) {
        throw new RuntimeException('WordPress product database is unavailable.');
    }

    /** @var PDO $pdo */
    $pdo = $connection['pdo'];
    $prefix = $connection['prefix'];
    $posts = $prefix . 'posts';
    $postmeta = $prefix . 'postmeta';

    $existsStmt = $pdo->prepare("SELECT ID FROM {$posts} WHERE ID = ? AND post_type IN ('product','product_variation') LIMIT 1");
    $existsStmt->execute([$productId]);
    if (!$existsStmt->fetchColumn()) {
        throw new RuntimeException('Product or variation was not found.');
    }

    $costValue = number_format($cost, 2, '.', '');
    $metaStmt = $pdo->prepare("SELECT meta_id FROM {$postmeta} WHERE post_id = ? AND meta_key = '_wc_cog_cost' LIMIT 1");
    $metaStmt->execute([$productId]);
    $metaId = $metaStmt->fetchColumn();
    if ($metaId) {
        $updateStmt = $pdo->prepare("UPDATE {$postmeta} SET meta_value = ? WHERE meta_id = ?");
        $updateStmt->execute([$costValue, (int) $metaId]);
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO {$postmeta} (post_id, meta_key, meta_value) VALUES (?, '_wc_cog_cost', ?)");
        $insertStmt->execute([$productId, $costValue]);
    }

    cor_report_log('Inventory product cost saved', [
        'endpoint' => 'wp_postmeta',
        'product_id' => $productId,
        'cost' => $costValue,
    ]);
}

function cor_inventory_filtered_rows(array $rows, string $search, string $category, string $stock, string $sort, string $dir): array
{
    $search = strtolower(trim($search));
    $category = trim($category);
    $filtered = array_filter($rows, static function (array $row) use ($search, $category, $stock): bool {
        if ($search !== '') {
            $name = strtolower((string) $row['name']);
            $sku = strtolower((string) $row['sku']);
            if (!cor_contains($name, $search) && !cor_contains($sku, $search)) {
                return false;
            }
        }
        if ($category !== '' && $category !== 'all') {
            if (!cor_contains(strtolower((string) $row['category']), strtolower($category))) {
                return false;
            }
        }
        if ($stock !== 'all' && ($row['stock_class'] ?? '') !== $stock) {
            return false;
        }

        return true;
    });
    $filtered = array_values($filtered);

    $allowedSorts = ['name', 'qty', 'retail_val', 'price', 'cost_val', 'profit'];
    if (!in_array($sort, $allowedSorts, true)) {
        $sort = 'name';
    }
    usort($filtered, static function (array $a, array $b) use ($sort, $dir): int {
        $left = $a[$sort] ?? '';
        $right = $b[$sort] ?? '';
        if (is_numeric($left) && is_numeric($right)) {
            $cmp = (float) $left <=> (float) $right;
        } else {
            $cmp = strcmp(strtolower((string) $left), strtolower((string) $right));
        }

        return $dir === 'desc' ? -$cmp : $cmp;
    });

    return $filtered;
}

function cor_inventory_sum(array $rows, string $key): float
{
    $sum = 0.0;
    foreach ($rows as $row) {
        $sum += (float) ($row[$key] ?? 0);
    }

    return $sum;
}

function cor_inventory_payload(string $search, string $category, string $stock, string $sort, string $dir): array
{
    $fetch = cor_fetch_inventory_result();
    $allRows = $fetch['rows'];
    $filteredRows = cor_inventory_filtered_rows($allRows, $search, $category, $stock, $sort, $dir);
    $stats = [
        'shown' => count($filteredRows),
        'total' => count($allRows),
        'units' => cor_inventory_sum($allRows, 'qty'),
        'cost_value' => cor_inventory_sum($allRows, 'cost_val'),
        'retail_value' => cor_inventory_sum($allRows, 'retail_val'),
        'shown_qty' => cor_inventory_sum($filteredRows, 'qty'),
        'shown_cost' => cor_inventory_sum($filteredRows, 'cost_val'),
        'shown_retail' => cor_inventory_sum($filteredRows, 'retail_val'),
        'in' => 0,
        'low' => 0,
        'out' => 0,
    ];
    $stats['gross_profit'] = $stats['retail_value'] - $stats['cost_value'];
    $stats['shown_profit'] = $stats['shown_retail'] - $stats['shown_cost'];
    $stats['margin_pct'] = $stats['retail_value'] > 0 ? (int) round(($stats['gross_profit'] / $stats['retail_value']) * 100) : 0;
    foreach ($allRows as $row) {
        $class = (string) ($row['stock_class'] ?? 'in');
        if (isset($stats[$class])) {
            $stats[$class]++;
        }
    }

    $rows = array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'variant' => (string) ($row['variant'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'sku' => (string) ($row['sku'] ?? '-'),
            'stock_class' => (string) ($row['stock_class'] ?? 'in'),
            'qty' => (float) ($row['qty'] ?? 0),
            'regular_price' => (float) ($row['regular_price'] ?? $row['price'] ?? 0),
            'sale_price' => (float) ($row['sale_price'] ?? 0),
            'cost' => (float) ($row['cost'] ?? 0),
            'cost_val' => (float) ($row['cost_val'] ?? 0),
            'retail_val' => (float) ($row['retail_val'] ?? 0),
            'profit' => (float) ($row['profit'] ?? 0),
        ];
    }, $filteredRows);

    return [
        'ok' => !$fetch['error'],
        'source' => $fetch['source'],
        'error' => $fetch['error'],
        'logs' => $fetch['logs'],
        'stats' => $stats,
        'rows' => $rows,
    ];
}

function cor_inventory_rows_from_products(array $productRows): array
{
    $rows = [];
    foreach ($productRows as $index => $row) {
        $qty = (float) ($row['qty'] ?? 0);
        $revenue = (float) ($row['rev'] ?? 0);
        $price = $qty > 0 ? $revenue / $qty : 0.0;
        $stockClass = 'in';
        if ($qty <= 0) {
            $stockClass = 'out';
        } elseif ($qty <= 5) {
            $stockClass = 'low';
        }
        $rows[] = [
            'id' => $index + 1,
            'name' => (string) ($row['product_name'] ?? 'Product'),
            'variant' => '',
            'category' => 'Synced order items',
            'sku' => trim((string) ($row['sku'] ?? '')) ?: '-',
            'price' => $price,
            'cost' => 0.0,
            'qty' => $qty,
            'cost_val' => 0.0,
            'retail_val' => $revenue,
            'profit' => $revenue,
            'stock_class' => $stockClass,
            'low_threshold' => 5,
        ];
    }

    return $rows;
}

function cor_export(array $filters, string $format): void
{
    $params = [];
    $where = cor_filtered_where($filters, $params);
    $rows = cor_query_rows(
        "SELECT o.order_number, o.created_at, o.customer_name, o.customer_contact, COALESCE(NULLIF(o.fulfilment_mode, ''), o.order_type) AS order_type, o.payment_method,
                o.total_amount, o.payment_status, o.status, COALESCE(e.full_name, 'Unassigned') AS packer_name
         FROM ops_orders o
         LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
         WHERE {$where}
         ORDER BY o.created_at DESC",
        $params
    );

    $filename = 'customer-orders-' . $filters['from'] . '-to-' . $filters['to'] . ($format === 'excel' ? '.xls' : '.csv');
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Order ID', 'Date', 'Customer', 'Mobile', 'Mode', 'Payment', 'Amount', 'Payment Status', 'Order Status', 'Packed By'], "\t");
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['order_number'],
                $row['created_at'],
                $row['customer_name'],
                $row['customer_contact'],
                ucwords((string) $row['order_type']),
                $row['payment_method'],
                number_format((float) $row['total_amount'], 2, '.', ''),
                $row['payment_status'],
                cor_order_status_label((string) $row['status']),
                $row['packer_name'],
            ], "\t");
        }
        fclose($out);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order ID', 'Date', 'Customer', 'Mobile', 'Mode', 'Payment', 'Amount', 'Payment Status', 'Order Status', 'Packed By']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['order_number'],
            $row['created_at'],
            $row['customer_name'],
            $row['customer_contact'],
            ucwords((string) $row['order_type']),
            $row['payment_method'],
            number_format((float) $row['total_amount'], 2, '.', ''),
            $row['payment_status'],
            cor_order_status_label((string) $row['status']),
            $row['packer_name'],
        ]);
    }
    fclose($out);
    exit;
}

function cor_export_products(array $filters, string $format): void
{
    $params = [];
    $where = cor_filtered_where($filters, $params);
    $rows = cor_query_rows(
        "SELECT oi.product_name,
                COALESCE(SUM(oi.quantity), 0) AS qty,
                COALESCE(SUM(CASE WHEN order_qty.qty > 0 THEN o.total_amount * (oi.quantity / order_qty.qty) ELSE 0 END), 0) AS rev
         FROM ops_order_items oi
         JOIN ops_orders o ON o.id = oi.order_id
         LEFT JOIN (
            SELECT order_id, SUM(quantity) AS qty
            FROM ops_order_items
            GROUP BY order_id
         ) order_qty ON order_qty.order_id = o.id
         WHERE {$where}
         GROUP BY oi.product_name
         ORDER BY rev DESC",
        $params
    );

    $total = array_reduce($rows, static fn(float $carry, array $row): float => $carry + (float) $row['rev'], 0.0);
    $filename = 'product-sales-' . $filters['from'] . '-to-' . $filters['to'] . ($format === 'excel' ? '.xls' : '.csv');
    header($format === 'excel' ? 'Content-Type: application/vnd.ms-excel; charset=utf-8' : 'Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Product', 'Units Sold', 'Revenue (N$)', '% of Total'], $format === 'excel' ? "\t" : ',');
    foreach ($rows as $index => $row) {
        $pct = $total > 0 ? ((float) $row['rev'] / $total) * 100 : 0;
        fputcsv($out, [
            $index + 1,
            $row['product_name'],
            number_format((float) $row['qty'], 2, '.', ''),
            number_format((float) $row['rev'], 2, '.', ''),
            cor_pct($pct),
        ], $format === 'excel' ? "\t" : ',');
    }
    fclose($out);
    exit;
}

function cor_export_inventory(string $format): void
{
    try {
        $rows = cor_fetch_inventory_rows();
    } catch (Throwable $e) {
        $rows = [];
    }
    $search = trim((string) ($_GET['inv_search'] ?? ''));
    $category = trim((string) ($_GET['inv_category'] ?? 'all')) ?: 'all';
    $stock = trim((string) ($_GET['inv_stock'] ?? 'all')) ?: 'all';
    $sort = trim((string) ($_GET['inv_sort'] ?? 'name')) ?: 'name';
    $dir = strtolower(trim((string) ($_GET['inv_dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
    $rows = cor_inventory_filtered_rows($rows, $search, $category, $stock, $sort, $dir);

    $delimiter = $format === 'excel' ? "\t" : ',';
    $filename = 'inventory-' . date('Y-m-d') . ($format === 'excel' ? '.xls' : '.csv');
    header($format === 'excel' ? 'Content-Type: application/vnd.ms-excel; charset=utf-8' : 'Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product', 'Category', 'Variant', 'SKU', 'Price', 'Cost', 'Qty', 'Cost Value', 'Retail Value', 'Profit'], $delimiter);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['name'],
            $row['category'],
            $row['variant'] ?: '-',
            $row['sku'],
            number_format((float) $row['price'], 2, '.', ''),
            number_format((float) $row['cost'], 2, '.', ''),
            number_format((float) $row['qty'], 2, '.', ''),
            number_format((float) $row['cost_val'], 2, '.', ''),
            number_format((float) $row['retail_val'], 2, '.', ''),
            number_format((float) $row['profit'], 2, '.', ''),
        ], $delimiter);
    }
    fclose($out);
    exit;
}

function cor_export_vat(array $filters, string $format, string $section): void
{
    $params = [];
    $where = cor_filtered_where($filters, $params);
    $delimiter = $format === 'excel' ? "\t" : ',';
    $filename = 'vat-' . ($section ?: 'summary') . '-' . $filters['from'] . '-to-' . $filters['to'] . ($format === 'excel' ? '.xls' : '.csv');

    header($format === 'excel' ? 'Content-Type: application/vnd.ms-excel; charset=utf-8' : 'Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    if ($section === 'daily') {
        $rows = cor_query_rows(
            "SELECT DATE(o.created_at) AS d,
                    COUNT(*) AS orders,
                    COALESCE(SUM(o.total_amount), 0) AS total_sales,
                    COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
             FROM ops_orders o
             WHERE {$where} AND COALESCE(o.payment_status, '') <> 'refunded' AND COALESCE(o.status, '') NOT IN ('cancelled','canceled','failed')
             GROUP BY DATE(o.created_at)
             ORDER BY d ASC",
            $params
        );
        fputcsv($out, ['Date', 'Orders', 'Total Sales', 'Shipping', 'VATable Sales', 'Ex-VAT', 'VAT (15%)'], $delimiter);
        foreach ($rows as $row) {
            $vatable = max(0.0, (float) $row['total_sales'] - (float) $row['shipping']);
            fputcsv($out, [
                $row['d'],
                (int) $row['orders'],
                number_format((float) $row['total_sales'], 2, '.', ''),
                number_format((float) $row['shipping'], 2, '.', ''),
                number_format($vatable, 2, '.', ''),
                number_format($vatable * (100 / 115), 2, '.', ''),
                number_format($vatable * (15 / 115), 2, '.', ''),
            ], $delimiter);
        }
        fclose($out);
        exit;
    }

    if ($section === 'payment') {
        $rows = cor_query_rows(
            "SELECT COALESCE(NULLIF(o.payment_method, ''), 'Other') AS method,
                    COUNT(*) AS orders,
                    COALESCE(SUM(o.total_amount), 0) AS total_incl,
                    COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
             FROM ops_orders o
             WHERE {$where} AND COALESCE(o.payment_status, '') <> 'refunded' AND COALESCE(o.status, '') NOT IN ('cancelled','canceled','failed')
             GROUP BY COALESCE(NULLIF(o.payment_method, ''), 'Other')
             ORDER BY total_incl DESC",
            $params
        );
        fputcsv($out, ['Payment Method', 'Orders', 'Total Incl VAT', 'VAT Portion'], $delimiter);
        foreach ($rows as $row) {
            $vatable = max(0.0, (float) $row['total_incl'] - (float) $row['shipping']);
            fputcsv($out, [$row['method'], (int) $row['orders'], number_format((float) $row['total_incl'], 2, '.', ''), number_format($vatable * (15 / 115), 2, '.', '')], $delimiter);
        }
        fclose($out);
        exit;
    }

    if ($section === 'products') {
        $rows = cor_query_rows(
            "SELECT oi.product_name,
                    COALESCE(SUM(oi.quantity), 0) AS qty,
                    COALESCE(SUM(CASE WHEN order_qty.qty > 0 THEN o.total_amount * (oi.quantity / order_qty.qty) ELSE 0 END), 0) AS total_incl
             FROM ops_order_items oi
             JOIN ops_orders o ON o.id = oi.order_id
             LEFT JOIN (
                SELECT order_id, SUM(quantity) AS qty
                FROM ops_order_items
                GROUP BY order_id
             ) order_qty ON order_qty.order_id = o.id
             WHERE {$where} AND COALESCE(o.payment_status, '') <> 'refunded' AND COALESCE(o.status, '') NOT IN ('cancelled','canceled','failed')
             GROUP BY oi.product_name
             ORDER BY total_incl DESC",
            $params
        );
        fputcsv($out, ['Product', 'Qty Sold', 'Total Sales Incl VAT', 'VAT Portion'], $delimiter);
        foreach ($rows as $row) {
            fputcsv($out, [$row['product_name'], number_format((float) $row['qty'], 2, '.', ''), number_format((float) $row['total_incl'], 2, '.', ''), number_format((float) $row['total_incl'] * (15 / 115), 2, '.', '')], $delimiter);
        }
        fclose($out);
        exit;
    }

    $rows = cor_query_rows(
        "SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
                COUNT(*) AS orders,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
         FROM ops_orders o
         WHERE {$where} AND COALESCE(o.payment_status, '') <> 'refunded' AND COALESCE(o.status, '') NOT IN ('cancelled','canceled','failed')",
        $params
    );
    $row = $rows[0] ?? ['total_sales' => 0, 'orders' => 0, 'shipping' => 0];
    $vatable = max(0.0, (float) $row['total_sales'] - (float) $row['shipping']);
    fputcsv($out, ['Metric', 'Amount'], $delimiter);
    fputcsv($out, ['Period', $filters['from'] . ' - ' . $filters['to']], $delimiter);
    fputcsv($out, ['Total Orders', (int) $row['orders']], $delimiter);
    fputcsv($out, ['Total Invoice Sales Incl VAT', number_format((float) $row['total_sales'], 2, '.', '')], $delimiter);
    fputcsv($out, ['Shipping Excluded', number_format((float) $row['shipping'], 2, '.', '')], $delimiter);
    fputcsv($out, ['VATable Sales', number_format($vatable, 2, '.', '')], $delimiter);
    fputcsv($out, ['Sales Ex-VAT', number_format($vatable * (100 / 115), 2, '.', '')], $delimiter);
    fputcsv($out, ['VAT Collected', number_format($vatable * (15 / 115), 2, '.', '')], $delimiter);
    fclose($out);
    exit;
}

function cor_payment_bucket(string $method): string
{
    $method = strtolower($method);
    if (cor_contains($method, 'cash') && !cor_contains($method, 'wallet') && !cor_contains($method, 'blue')) {
        return 'cash';
    }
    if (cor_contains($method, 'card') || cor_contains($method, 'swipe')) {
        return 'card';
    }
    if ($method === 'eft' || cor_contains($method, 'eft')) {
        return 'eft';
    }

    return 'wallet';
}

function cor_export_monthly(array $filters, string $format): void
{
    $params = [];
    $where = cor_filtered_where($filters, $params);
    $delimiter = $format === 'excel' ? "\t" : ',';
    $filename = 'monthly-summary-' . $filters['from'] . '-to-' . $filters['to'] . ($format === 'excel' ? '.xls' : '.csv');
    $summary = cor_query_one(
        "SELECT COUNT(*) AS orders,
                COALESCE(SUM(o.total_amount), 0) AS total_sales,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
         FROM ops_orders o
         WHERE {$where} AND COALESCE(o.payment_status, '') <> 'refunded' AND COALESCE(o.status, '') NOT IN ('cancelled','canceled','failed')",
        $params
    );
    $refundRows = cor_query_rows(
        "SELECT o.refund_total, o.total_amount
         FROM ops_orders o
         WHERE {$where} AND (o.payment_status = 'refunded' OR o.refund_total > 0)",
        $params
    );
    $payRows = cor_query_rows(
        "SELECT COALESCE(NULLIF(o.payment_method, ''), 'Other') AS method,
                COALESCE(SUM(o.total_amount), 0) AS total
         FROM ops_orders o
         WHERE {$where} AND COALESCE(o.payment_status, '') <> 'refunded' AND COALESCE(o.status, '') NOT IN ('cancelled','canceled','failed')
         GROUP BY COALESCE(NULLIF(o.payment_method, ''), 'Other')",
        $params
    );
    $cash = $card = $eft = $wallet = 0.0;
    foreach ($payRows as $row) {
        $bucket = cor_payment_bucket((string) $row['method']);
        if ($bucket === 'cash') {
            $cash += (float) $row['total'];
        } elseif ($bucket === 'card') {
            $card += (float) $row['total'];
        } elseif ($bucket === 'eft') {
            $eft += (float) $row['total'];
        } else {
            $wallet += (float) $row['total'];
        }
    }
    $totalSales = (float) ($summary['total_sales'] ?? 0);
    $orders = (int) ($summary['orders'] ?? 0);
    $shipping = (float) ($summary['shipping'] ?? 0);
    $refunds = array_reduce($refundRows, static fn(float $carry, array $row): float => $carry + (float) ($row['refund_total'] ?: $row['total_amount']), 0.0);
    $netSales = $totalSales - $refunds;
    $vatable = max(0.0, $totalSales - $shipping);
    $vat = round($vatable * (15 / 115), 2);
    $exVat = round($vatable * (100 / 115), 2);
    $cogs = 0.0;
    $grossProfit = $netSales - $shipping - $cogs;
    $margin = $netSales > 0 ? round(($grossProfit / $netSales) * 100) : 0;
    $stockCost = 0.0;
    $stockRetail = 0.0;
    $potentialMargin = $stockRetail - $stockCost;

    header($format === 'excel' ? 'Content-Type: application/vnd.ms-excel; charset=utf-8' : 'Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric', 'Amount', 'Notes'], $delimiter);
    fputcsv($out, ['--- SALES ---', '', ''], $delimiter);
    fputcsv($out, ['Total Invoice Sales', cor_money($totalSales), $orders . ' orders'], $delimiter);
    fputcsv($out, ['Cash Sales', cor_money($cash), ''], $delimiter);
    fputcsv($out, ['Card / Swipe Sales', cor_money($card), ''], $delimiter);
    fputcsv($out, ['EFT Sales', cor_money($eft), ''], $delimiter);
    fputcsv($out, ['Other / Wallet Sales', cor_money($wallet), ''], $delimiter);
    fputcsv($out, ['Delivery Fees', cor_money($shipping), ''], $delimiter);
    fputcsv($out, ['Refunds Issued', '-' . cor_money($refunds), ''], $delimiter);
    fputcsv($out, ['Net Sales', cor_money($netSales), ''], $delimiter);
    fputcsv($out, ['--- VAT ---', '', ''], $delimiter);
    fputcsv($out, ['VATable Sales', cor_money($vatable), ''], $delimiter);
    fputcsv($out, ['VAT Amount (15%)', cor_money($vat), ''], $delimiter);
    fputcsv($out, ['Sales Excl. VAT', cor_money($exVat), ''], $delimiter);
    fputcsv($out, ['--- PROFIT (ESTIMATED) ---', '', ''], $delimiter);
    fputcsv($out, ['Net Sales', cor_money($netSales), ''], $delimiter);
    fputcsv($out, ['Shipping Deducted', '-' . cor_money($shipping), ''], $delimiter);
    fputcsv($out, ['Cost of Goods Sold (est.)', '-' . cor_money($cogs), ''], $delimiter);
    fputcsv($out, ['Gross Profit (est.)', cor_money($grossProfit), $margin . '% margin'], $delimiter);
    fputcsv($out, ['--- INVENTORY ---', '', ''], $delimiter);
    fputcsv($out, ['Stock Cost Value', cor_money($stockCost), ''], $delimiter);
    fputcsv($out, ['Stock Retail Value', cor_money($stockRetail), ''], $delimiter);
    fputcsv($out, ['Potential Margin', cor_money($potentialMargin), ''], $delimiter);
    fclose($out);
    exit;
}

$range = trim((string) ($_GET['range'] ?? 'month'));
if (!in_array($range, ['today', 'week', 'month', 'year', 'custom'], true)) {
    $range = 'month';
}

[$dateFrom, $dateTo] = cor_period_dates($range, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
$filters = [
    'range' => $range,
    'from' => $dateFrom,
    'to' => $dateTo,
    'status' => trim((string) ($_GET['status'] ?? 'all')) ?: 'all',
    'mode' => trim((string) ($_GET['mode'] ?? 'all')) ?: 'all',
    'payment' => trim((string) ($_GET['payment'] ?? 'all')) ?: 'all',
    'tab' => trim((string) ($_GET['tab'] ?? 'sales')) ?: 'sales',
];

$validStatuses = array_merge(['all'], array_keys(OPS_ORDER_STATUSES));
if (!in_array($filters['status'], $validStatuses, true)) {
    $filters['status'] = 'all';
}
if (!in_array($filters['mode'], ['all', 'collection', 'delivery', 'courier'], true)) {
    $filters['mode'] = 'all';
}
$validTabs = ['sales', 'products', 'delivery', 'vat', 'monthly', 'inventory', 'orders'];
if (!in_array($filters['tab'], $validTabs, true)) {
    $filters['tab'] = 'sales';
}

$reportSyncError = null;

if ($ready && isset($_GET['export']) && in_array((string) $_GET['export'], ['csv', 'excel'], true)) {
    if ($filters['tab'] === 'products') {
        cor_export_products($filters, (string) $_GET['export']);
    }
    if ($filters['tab'] === 'vat') {
        cor_export_vat($filters, (string) $_GET['export'], (string) ($_GET['section'] ?? 'summary'));
    }
    if ($filters['tab'] === 'monthly') {
        cor_export_monthly($filters, (string) $_GET['export']);
    }
    if ($filters['tab'] === 'inventory') {
        cor_export_inventory((string) $_GET['export']);
    }
    cor_export($filters, (string) $_GET['export']);
}

$paymentOptions = $ready ? array_map(
    static fn(array $row): string => (string) $row['payment_method'],
    cor_query_rows("SELECT DISTINCT payment_method FROM ops_orders WHERE payment_method IS NOT NULL AND payment_method <> '' ORDER BY payment_method")
) : [];

$summary = [
    'total_orders' => 0,
    'total_revenue' => 0.0,
    'aov' => 0.0,
    'completed' => 0,
    'processing' => 0,
    'pending' => 0,
    'tax_collected' => 0.0,
    'discounts' => 0.0,
];
$statusRows = [];
$paymentRows = [];
$modeRows = [];
$staffRows = [];
$productRows = [];
$refundRows = [];
$deliveryRows = [];
$deliveryDayRows = [];
$deliveryOrderRows = [];
$vatRows = [];
$vatDailyRows = [];
$vatPaymentRows = [];
$vatProductRows = [];
$inventoryRows = [];
$inventoryAllRows = [];
$inventoryFilteredRows = [];
$inventoryTopRows = [];
$inventoryCategories = [];
$inventoryFetch = ['rows' => [], 'source' => 'not_loaded', 'error' => null, 'logs' => []];
$inventoryError = null;
$inventorySource = 'not_loaded';
$inventoryStats = [
    'shown' => 0,
    'total' => 0,
    'units' => 0.0,
    'cost_value' => 0.0,
    'retail_value' => 0.0,
    'gross_profit' => 0.0,
    'margin_pct' => 0,
    'low' => 0,
    'out' => 0,
    'in' => 0,
    'shown_qty' => 0.0,
    'shown_cost' => 0.0,
    'shown_retail' => 0.0,
    'shown_profit' => 0.0,
];
$daily14Rows = [];
$orderRows = [];
$refundSummary = ['count' => 0, 'amount' => 0.0, 'pct' => 0.0];
$vatSummary = ['gross' => 0.0, 'vat' => 0.0, 'net' => 0.0];
$vatMetrics = [
    'total_sales' => 0.0,
    'order_count' => 0,
    'shipping' => 0.0,
    'refunds' => 0.0,
    'vatable_sales' => 0.0,
    'vat_collected' => 0.0,
    'sales_ex_vat' => 0.0,
    'vat_on_refunds' => 0.0,
    'net_vat_payable' => 0.0,
];
$vatDailyTotals = ['orders' => 0, 'total_sales' => 0.0, 'shipping' => 0.0, 'vatable' => 0.0, 'ex_vat' => 0.0, 'vat_amount' => 0.0];
$vatPaymentTotals = ['orders' => 0, 'total_incl' => 0.0, 'vat_portion' => 0.0];
$vatProductTotals = ['qty' => 0.0, 'total_incl' => 0.0, 'vat_portion' => 0.0];
$monthlySummary = [
    'total_sales' => 0.0,
    'order_count' => 0,
    'cash' => 0.0,
    'card' => 0.0,
    'eft' => 0.0,
    'wallet' => 0.0,
    'shipping' => 0.0,
    'refunds' => 0.0,
    'net_sales' => 0.0,
    'vatable' => 0.0,
    'vat' => 0.0,
    'ex_vat' => 0.0,
    'cogs' => 0.0,
    'gross_profit' => 0.0,
    'margin_pct' => 0,
    'stock_cost' => 0.0,
    'stock_retail' => 0.0,
    'potential_margin' => 0.0,
];
$deliverySummary = ['fees' => 0.0, 'orders' => 0, 'avg' => 0.0, 'courier' => 0, 'total_cost' => 0.0, 'total_orders' => 0, 'breakdown_avg' => 0.0];
$deliveryOrdersCount = 0;
$deliverySearch = trim((string) ($_GET['del_search'] ?? ''));
$deliveryStatus = trim((string) ($_GET['del_status'] ?? 'all')) ?: 'all';
$inventorySearch = trim((string) ($_GET['inv_search'] ?? ''));
$inventoryCategory = trim((string) ($_GET['inv_category'] ?? 'all')) ?: 'all';
$inventoryStock = trim((string) ($_GET['inv_stock'] ?? 'all')) ?: 'all';
$inventorySort = trim((string) ($_GET['inv_sort'] ?? 'name')) ?: 'name';
$inventoryDir = strtolower(trim((string) ($_GET['inv_dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
$inventoryLiveRequested = (string) ($_GET['inv_live'] ?? '') === '1' || (string) ($_GET['tab'] ?? '') === 'inventory';
$totalPages = 1;
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['inventory_cost_ajax'] ?? '') === '1') {
    header('Content-Type: application/json');
    try {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $cost = (float) str_replace(',', '.', (string) ($_POST['cost'] ?? '0'));
        cor_save_inventory_cost($productId, $cost);
        echo json_encode([
            'ok' => true,
            'product_id' => $productId,
            'cost' => number_format($cost, 2, '.', ''),
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        cor_report_log('Inventory product cost save failed', [
            'endpoint' => 'wp_postmeta',
            'product_id' => (int) ($_POST['product_id'] ?? 0),
            'error' => $e->getMessage(),
        ]);
        http_response_code(200);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if ($ready && (string) ($_GET['inventory_ajax'] ?? '') === '1') {
    header('Content-Type: application/json');
    try {
        $payload = cor_inventory_payload($inventorySearch, $inventoryCategory, $inventoryStock, $inventorySort, $inventoryDir);
        cor_report_log('Inventory AJAX completed', [
            'endpoint' => 'products',
            'ok' => (bool) ($payload['ok'] ?? false),
            'source' => $payload['source'] ?? '',
            'products_returned' => (int) ($payload['stats']['total'] ?? 0),
            'rows_returned' => count($payload['rows'] ?? []),
            'error' => $payload['error'] ?? null,
        ]);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        cor_report_log('Inventory AJAX failed', [
            'endpoint' => 'products',
            'error' => $e->getMessage(),
        ]);
        http_response_code(200);
        echo json_encode([
            'ok' => false,
            'source' => 'woocommerce_rest',
            'error' => $e->getMessage(),
            'stats' => ['shown' => 0, 'total' => 0, 'units' => 0, 'cost_value' => 0, 'retail_value' => 0, 'gross_profit' => 0, 'margin_pct' => 0, 'low' => 0, 'out' => 0, 'in' => 0, 'shown_qty' => 0, 'shown_cost' => 0, 'shown_retail' => 0, 'shown_profit' => 0],
            'rows' => [],
        ], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if ($ready) {
    $params = [];
    $where = cor_filtered_where($filters, $params);
    $summaryRow = cor_query_one(
        "SELECT COUNT(*) AS total_orders,
                SUM(CASE WHEN COALESCE(payment_status, '') <> 'refunded' AND COALESCE(status, '') NOT IN ('cancelled','canceled','failed') THEN 1 ELSE 0 END) AS revenue_orders,
                COALESCE(SUM(CASE WHEN COALESCE(payment_status, '') <> 'refunded' AND COALESCE(status, '') NOT IN ('cancelled','canceled','failed') THEN COALESCE(total_amount, 0) ELSE 0 END), 0) AS total_revenue,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status IN ('in_progress','packed','verified','ready_for_collection','ready_for_courier','ready_for_delivery') THEN 1 ELSE 0 END) AS processing,
                SUM(CASE WHEN status IN ('new_order','assigned') THEN 1 ELSE 0 END) AS pending,
                COALESCE(SUM(CASE WHEN COALESCE(payment_status, '') <> 'refunded' AND COALESCE(status, '') NOT IN ('cancelled','canceled','failed') THEN COALESCE(tax_total, 0) + COALESCE(shipping_tax_total, 0) ELSE 0 END), 0) AS tax_collected,
                COALESCE(SUM(CASE WHEN COALESCE(payment_status, '') <> 'refunded' AND COALESCE(status, '') NOT IN ('cancelled','canceled','failed') THEN COALESCE(discount_total, 0) ELSE 0 END), 0) AS discounts
         FROM ops_orders o
         WHERE {$where}",
        $params
    );
    $summary['total_orders'] = (int) ($summaryRow['total_orders'] ?? 0);
    $summary['total_revenue'] = (float) ($summaryRow['total_revenue'] ?? 0);
    $revenueOrders = (int) ($summaryRow['revenue_orders'] ?? 0);
    $summary['aov'] = $revenueOrders > 0 ? $summary['total_revenue'] / $revenueOrders : 0.0;
    $summary['completed'] = (int) ($summaryRow['completed'] ?? 0);
    $summary['processing'] = (int) ($summaryRow['processing'] ?? 0);
    $summary['pending'] = (int) ($summaryRow['pending'] ?? 0);
    $summary['tax_collected'] = (float) ($summaryRow['tax_collected'] ?? 0);
    $summary['discounts'] = (float) ($summaryRow['discounts'] ?? 0);

    $statusRows = cor_query_rows(
        "SELECT status AS label, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS rev
         FROM ops_orders o
         WHERE {$where}
         GROUP BY status
         ORDER BY cnt DESC",
        $params
    );
    $paymentRows = cor_query_rows(
        "SELECT COALESCE(NULLIF(payment_method, ''), 'Other') AS label, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS rev
         FROM ops_orders o
         WHERE {$where}
         GROUP BY COALESCE(NULLIF(payment_method, ''), 'Other')
         ORDER BY rev DESC",
        $params
    );
    if ($summary['total_revenue'] <= 0 && $paymentRows) {
        $summary['total_revenue'] = array_reduce($paymentRows, static fn(float $carry, array $row): float => $carry + (float) $row['rev'], 0.0);
        $summary['total_orders'] = array_reduce($paymentRows, static fn(int $carry, array $row): int => $carry + (int) $row['cnt'], 0);
        $summary['aov'] = $summary['total_orders'] > 0 ? $summary['total_revenue'] / $summary['total_orders'] : 0.0;
        $summary['tax_collected'] = $summary['total_revenue'] * (0.15 / 1.15);
        cor_report_log('Customer Orders Report summary fell back to payment rows', [
            'orders_returned' => $summary['total_orders'],
            'total_revenue' => $summary['total_revenue'],
            'payment_methods_returned' => count($paymentRows),
        ]);
    }
    $modeRows = cor_query_rows(
        "SELECT COALESCE(NULLIF(fulfilment_mode, ''), NULLIF(order_type, ''), 'collection') AS label, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS rev
         FROM ops_orders o
         WHERE {$where}
         GROUP BY COALESCE(NULLIF(fulfilment_mode, ''), NULLIF(order_type, ''), 'collection')
         ORDER BY rev DESC",
        $params
    );
    $staffRows = cor_query_rows(
        "SELECT COALESCE(e.full_name, 'Unassigned') AS label, COUNT(*) AS cnt, COALESCE(SUM(o.total_amount), 0) AS rev
         FROM ops_orders o
         LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
         WHERE {$where}
         GROUP BY COALESCE(e.full_name, 'Unassigned')
         ORDER BY cnt DESC",
        $params
    );
    $productRows = cor_query_rows(
        "SELECT oi.product_name,
                COALESCE(NULLIF(MAX(oi.sku), ''), '-') AS sku,
                COALESCE(SUM(oi.quantity), 0) AS qty,
                COALESCE(SUM(CASE WHEN order_qty.qty > 0 THEN o.total_amount * (oi.quantity / order_qty.qty) ELSE 0 END), 0) AS rev
         FROM ops_order_items oi
         JOIN ops_orders o ON o.id = oi.order_id
         LEFT JOIN (
            SELECT order_id, SUM(quantity) AS qty
            FROM ops_order_items
            GROUP BY order_id
         ) order_qty ON order_qty.order_id = o.id
         WHERE {$where}
         GROUP BY oi.product_name
         ORDER BY rev DESC",
        $params
    );
    $refundRows = cor_query_rows(
        "SELECT o.order_number, o.created_at, o.customer_name, o.refund_total, o.total_amount, o.payment_status, o.status
         FROM ops_orders o
         WHERE {$where} AND (o.payment_status = 'refunded' OR o.refund_total > 0)
         ORDER BY o.created_at DESC",
        $params
    );
    $refundSummary['count'] = count($refundRows);
    $refundSummary['amount'] = array_reduce($refundRows, static fn(float $carry, array $row): float => $carry + (float) ($row['refund_total'] ?: $row['total_amount']), 0.0);
    $refundSummary['pct'] = $summary['total_revenue'] > 0 ? ($refundSummary['amount'] / $summary['total_revenue']) * 100 : 0.0;

    $deliveryWhere = $where . " AND ((o.shipping_total + o.shipping_tax_total) > 0 OR COALESCE(NULLIF(o.fulfilment_mode, ''), o.order_type) IN ('delivery','courier'))";
    $deliveryRows = cor_query_rows(
        "SELECT COALESCE(NULLIF(o.fulfilment_mode, ''), NULLIF(o.order_type, ''), 'collection') AS label,
                COUNT(*) AS cnt,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS total_cost,
                COALESCE(AVG(o.shipping_total + o.shipping_tax_total), 0) AS avg_cost
         FROM ops_orders o
         WHERE {$deliveryWhere}
         GROUP BY COALESCE(NULLIF(o.fulfilment_mode, ''), NULLIF(o.order_type, ''), 'collection')
         ORDER BY total_cost DESC, cnt DESC",
        $params
    );
    $deliverySummary['fees'] = array_reduce($deliveryRows, static fn(float $carry, array $row): float => $carry + (float) $row['total_cost'], 0.0);
    $deliverySummary['orders'] = array_reduce($deliveryRows, static fn(int $carry, array $row): int => $carry + (int) $row['cnt'], 0);
    $deliverySummary['avg'] = $deliverySummary['orders'] > 0 ? $deliverySummary['fees'] / $deliverySummary['orders'] : 0.0;
    $deliverySummary['courier'] = array_reduce($deliveryRows, static function (int $carry, array $row): int {
        return $carry + (strtolower((string) $row['label']) === 'courier' ? (int) $row['cnt'] : 0);
    }, 0);
    $deliverySummary['total_cost'] = $deliverySummary['fees'];
    $deliverySummary['total_orders'] = $deliverySummary['orders'];
    $deliverySummary['breakdown_avg'] = $deliverySummary['avg'];
    $deliveryDayRows = cor_query_rows(
        "SELECT DATE(o.created_at) AS d,
                COUNT(*) AS orders,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS fees
         FROM ops_orders o
         WHERE {$deliveryWhere}
         GROUP BY DATE(o.created_at)
         ORDER BY d ASC",
        $params
    );
    $deliveryOrderParams = $params;
    $deliveryOrderWhere = $deliveryWhere;
    if ($deliveryStatus !== 'all' && in_array($deliveryStatus, $validStatuses, true)) {
        $deliveryOrderWhere .= " AND o.status = ?";
        $deliveryOrderParams[] = $deliveryStatus;
    }
    if ($deliverySearch !== '') {
        $deliveryOrderWhere .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_contact LIKE ?)";
        $needle = '%' . $deliverySearch . '%';
        $deliveryOrderParams[] = $needle;
        $deliveryOrderParams[] = $needle;
        $deliveryOrderParams[] = $needle;
    }
    $deliveryCountRow = cor_query_one("SELECT COUNT(*) AS cnt FROM ops_orders o WHERE {$deliveryOrderWhere}", $deliveryOrderParams);
    $deliveryOrdersCount = (int) ($deliveryCountRow['cnt'] ?? 0);
    $deliveryOrderRows = cor_query_rows(
        "SELECT o.order_number, o.created_at, o.customer_name, o.customer_contact, COALESCE(NULLIF(o.fulfilment_mode, ''), o.order_type) AS order_type,
                COALESCE(o.shipping_total + o.shipping_tax_total, 0) AS delivery_cost,
                o.status
         FROM ops_orders o
         WHERE {$deliveryOrderWhere}
         ORDER BY o.created_at DESC
         LIMIT 50",
        $deliveryOrderParams
    );

    $vatRows = cor_query_rows(
        "SELECT DATE(o.created_at) AS d,
                COUNT(*) AS orders,
                COALESCE(SUM(o.total_amount), 0) AS total_sales,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
         FROM ops_orders o
         WHERE {$where} AND COALESCE(o.payment_status, '') <> 'refunded' AND COALESCE(o.status, '') NOT IN ('cancelled','canceled','failed')
         GROUP BY DATE(o.created_at)
         ORDER BY d ASC",
        $params
    );
    foreach ($vatRows as &$vatRow) {
        $vatRow['orders'] = (int) $vatRow['orders'];
        $vatRow['total_sales'] = (float) $vatRow['total_sales'];
        $vatRow['shipping'] = (float) $vatRow['shipping'];
        $vatRow['vatable'] = max(0.0, $vatRow['total_sales'] - $vatRow['shipping']);
        $vatRow['ex_vat'] = round($vatRow['vatable'] * (100 / 115), 2);
        $vatRow['vat_amount'] = round($vatRow['vatable'] * (15 / 115), 2);
    }
    unset($vatRow);
    $vatDailyRows = $vatRows;
    $vatDailyTotals = [
        'orders' => array_reduce($vatDailyRows, static fn(int $carry, array $row): int => $carry + (int) $row['orders'], 0),
        'total_sales' => array_reduce($vatDailyRows, static fn(float $carry, array $row): float => $carry + (float) $row['total_sales'], 0.0),
        'shipping' => array_reduce($vatDailyRows, static fn(float $carry, array $row): float => $carry + (float) $row['shipping'], 0.0),
        'vatable' => array_reduce($vatDailyRows, static fn(float $carry, array $row): float => $carry + (float) $row['vatable'], 0.0),
        'ex_vat' => array_reduce($vatDailyRows, static fn(float $carry, array $row): float => $carry + (float) $row['ex_vat'], 0.0),
        'vat_amount' => array_reduce($vatDailyRows, static fn(float $carry, array $row): float => $carry + (float) $row['vat_amount'], 0.0),
    ];
    $vatMetrics['total_sales'] = $vatDailyTotals['total_sales'];
    $vatMetrics['order_count'] = $vatDailyTotals['orders'];
    $vatMetrics['shipping'] = $vatDailyTotals['shipping'];
    $vatMetrics['refunds'] = $refundSummary['amount'];
    $vatMetrics['vatable_sales'] = $vatDailyTotals['vatable'];
    $vatMetrics['vat_collected'] = $vatDailyTotals['vat_amount'];
    $vatMetrics['sales_ex_vat'] = $vatDailyTotals['ex_vat'];
    $vatMetrics['vat_on_refunds'] = round($vatMetrics['refunds'] * (15 / 115), 2);
    $vatMetrics['net_vat_payable'] = $vatMetrics['vat_collected'] - $vatMetrics['vat_on_refunds'];
    $vatSummary['gross'] = $vatMetrics['total_sales'];
    $vatSummary['vat'] = $vatMetrics['vat_collected'];
    $vatSummary['net'] = $vatMetrics['sales_ex_vat'];

    $vatPaymentRows = cor_query_rows(
        "SELECT COALESCE(NULLIF(o.payment_method, ''), 'Other') AS method,
                COUNT(*) AS orders,
                COALESCE(SUM(o.total_amount), 0) AS total_incl,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
         FROM ops_orders o
         WHERE {$where} AND COALESCE(o.payment_status, '') <> 'refunded' AND COALESCE(o.status, '') NOT IN ('cancelled','canceled','failed')
         GROUP BY COALESCE(NULLIF(o.payment_method, ''), 'Other')
         ORDER BY total_incl DESC",
        $params
    );
    foreach ($vatPaymentRows as &$vatPaymentRow) {
        $vatablePayment = max(0.0, (float) $vatPaymentRow['total_incl'] - (float) $vatPaymentRow['shipping']);
        $vatPaymentRow['vat_portion'] = round($vatablePayment * (15 / 115), 2);
    }
    unset($vatPaymentRow);
    $vatPaymentTotals = [
        'orders' => array_reduce($vatPaymentRows, static fn(int $carry, array $row): int => $carry + (int) $row['orders'], 0),
        'total_incl' => array_reduce($vatPaymentRows, static fn(float $carry, array $row): float => $carry + (float) $row['total_incl'], 0.0),
        'vat_portion' => array_reduce($vatPaymentRows, static fn(float $carry, array $row): float => $carry + (float) $row['vat_portion'], 0.0),
    ];
    $vatProductRows = array_map(static function (array $row): array {
        $totalIncl = (float) $row['rev'];
        return [
            'product' => (string) $row['product_name'],
            'qty_sold' => (float) $row['qty'],
            'total_incl' => $totalIncl,
            'vat_portion' => round($totalIncl * (15 / 115), 2),
        ];
    }, $productRows);
    $vatProductTotals = [
        'qty' => array_reduce($vatProductRows, static fn(float $carry, array $row): float => $carry + (float) $row['qty_sold'], 0.0),
        'total_incl' => array_reduce($vatProductRows, static fn(float $carry, array $row): float => $carry + (float) $row['total_incl'], 0.0),
        'vat_portion' => array_reduce($vatProductRows, static fn(float $carry, array $row): float => $carry + (float) $row['vat_portion'], 0.0),
    ];
    $monthlySummary['total_sales'] = $vatMetrics['total_sales'];
    $monthlySummary['order_count'] = $vatMetrics['order_count'];
    $monthlySummary['shipping'] = $vatMetrics['shipping'];
    $monthlySummary['refunds'] = $refundSummary['amount'];
    foreach ($paymentRows as $row) {
        $bucket = cor_payment_bucket((string) $row['label']);
        $monthlySummary[$bucket] += (float) $row['rev'];
    }
    $monthlySummary['net_sales'] = $monthlySummary['total_sales'] - $monthlySummary['refunds'];
    $monthlySummary['vatable'] = $vatMetrics['vatable_sales'];
    $monthlySummary['vat'] = $vatMetrics['vat_collected'];
    $monthlySummary['ex_vat'] = $vatMetrics['sales_ex_vat'];
    $monthlySummary['cogs'] = 0.0;
    $monthlySummary['gross_profit'] = $monthlySummary['net_sales'] - $monthlySummary['shipping'] - $monthlySummary['cogs'];
    $monthlySummary['margin_pct'] = $monthlySummary['net_sales'] > 0 ? (int) round(($monthlySummary['gross_profit'] / $monthlySummary['net_sales']) * 100) : 0;
    $monthlySummary['stock_cost'] = 0.0;
    $monthlySummary['stock_retail'] = 0.0;
    $monthlySummary['potential_margin'] = $monthlySummary['stock_retail'] - $monthlySummary['stock_cost'];

    $inventoryRows = cor_query_rows(
        "SELECT oi.product_name,
                COALESCE(NULLIF(MAX(oi.sku), ''), '-') AS sku,
                COALESCE(SUM(oi.quantity), 0) AS sold_qty,
                COUNT(DISTINCT oi.order_id) AS order_count,
                MAX(o.created_at) AS last_sold_at
         FROM ops_order_items oi
         JOIN ops_orders o ON o.id = oi.order_id
         WHERE {$where}
         GROUP BY oi.product_name
         ORDER BY sold_qty DESC
         LIMIT 100",
        $params
    );
    if (in_array($filters['tab'], ['inventory', 'monthly'], true) && $inventoryLiveRequested) {
        $inventoryFetch = cor_fetch_inventory_result();
        $inventoryAllRows = $inventoryFetch['rows'];
        $inventoryError = $inventoryFetch['error'];
        $inventorySource = $inventoryFetch['source'];
    } elseif ($filters['tab'] === 'inventory') {
        $inventoryError = 'Live inventory has not been requested yet. Click Refresh to load products from WooCommerce.';
    }
    $inventoryCategories = [];
    foreach ($inventoryAllRows as $row) {
        foreach (explode(',', (string) ($row['category'] ?? '')) as $category) {
            $category = trim($category);
            if ($category !== '') {
                $inventoryCategories[$category] = $category;
            }
        }
    }
    ksort($inventoryCategories);
    if (!in_array($inventoryStock, ['all', 'in', 'low', 'out'], true)) {
        $inventoryStock = 'all';
    }
    $inventoryFilteredRows = cor_inventory_filtered_rows(
        $inventoryAllRows,
        $inventorySearch,
        $inventoryCategory,
        $inventoryStock,
        $inventorySort,
        $inventoryDir
    );
    $inventoryTopRows = $inventoryFilteredRows;
    usort($inventoryTopRows, static function (array $a, array $b): int {
        return (float) ($b['retail_val'] ?? 0) <=> (float) ($a['retail_val'] ?? 0);
    });
    $inventoryTopRows = array_slice(array_filter($inventoryTopRows, static function (array $row): bool {
        return (float) ($row['retail_val'] ?? 0) > 0;
    }), 0, 8);
    $inventoryStats['shown'] = count($inventoryFilteredRows);
    $inventoryStats['total'] = count($inventoryAllRows);
    $inventoryStats['shown_qty'] = cor_inventory_sum($inventoryFilteredRows, 'qty');
    $inventoryStats['shown_cost'] = cor_inventory_sum($inventoryFilteredRows, 'cost_val');
    $inventoryStats['shown_retail'] = cor_inventory_sum($inventoryFilteredRows, 'retail_val');
    $inventoryStats['shown_profit'] = $inventoryStats['shown_retail'] - $inventoryStats['shown_cost'];
    $inventoryStats['units'] = cor_inventory_sum($inventoryAllRows, 'qty');
    $inventoryStats['cost_value'] = cor_inventory_sum($inventoryAllRows, 'cost_val');
    $inventoryStats['retail_value'] = cor_inventory_sum($inventoryAllRows, 'retail_val');
    $inventoryStats['gross_profit'] = $inventoryStats['retail_value'] - $inventoryStats['cost_value'];
    $inventoryStats['margin_pct'] = $inventoryStats['retail_value'] > 0 ? (int) round(($inventoryStats['gross_profit'] / $inventoryStats['retail_value']) * 100) : 0;
    foreach ($inventoryAllRows as $row) {
        $class = (string) ($row['stock_class'] ?? 'in');
        if (isset($inventoryStats[$class])) {
            $inventoryStats[$class]++;
        }
    }
    if ($inventoryStats['total'] > 0) {
        $monthlySummary['stock_cost'] = $inventoryStats['cost_value'];
        $monthlySummary['stock_retail'] = $inventoryStats['retail_value'];
        $monthlySummary['potential_margin'] = $inventoryStats['gross_profit'];
    }
    cor_report_log('Customer Orders Report loaded', [
        'tab' => $filters['tab'],
        'range' => $filters['range'],
        'from' => $filters['from'],
        'to' => $filters['to'],
        'orders_returned' => $summary['total_orders'],
        'payment_methods_returned' => count($paymentRows),
        'inventory_products_returned' => $inventoryStats['total'],
        'inventory_source' => $inventorySource,
        'inventory_error' => $inventoryError,
    ]);
    $daily14Rows = cor_query_rows(
        "SELECT DATE(o.created_at) AS d, COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS rev
         FROM ops_orders o
         WHERE DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
           AND COALESCE(o.payment_status, '') <> 'refunded'
           AND COALESCE(o.status, '') NOT IN ('cancelled','canceled','failed')
         GROUP BY DATE(o.created_at)
         ORDER BY d ASC"
    );

    $countRow = cor_query_one("SELECT COUNT(*) AS cnt FROM ops_orders o WHERE {$where}", $params);
    $totalPages = max(1, (int) ceil(((int) ($countRow['cnt'] ?? 0)) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $orderRows = cor_query_rows(
        "SELECT o.order_number, o.created_at, o.customer_name, o.customer_contact, COALESCE(NULLIF(o.fulfilment_mode, ''), o.order_type) AS order_type, o.payment_method,
                o.total_amount, o.payment_status, o.status, COALESCE(e.full_name, 'Unassigned') AS packer_name
         FROM ops_orders o
         LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
         WHERE {$where}
         ORDER BY o.created_at DESC
         LIMIT {$perPage} OFFSET {$offset}",
        $params
    );
}

$maxPayment = max(1.0, ...array_map(static fn(array $row): float => (float) ($row['rev'] ?? 0), $paymentRows ?: [['rev' => 0]]));
$maxMode = max(1.0, ...array_map(static fn(array $row): float => (float) ($row['rev'] ?? 0), $modeRows ?: [['rev' => 0]]));
$maxStaff = max(1.0, ...array_map(static fn(array $row): float => (float) ($row['rev'] ?? 0), $staffRows ?: [['rev' => 0]]));
$maxProduct = max(1.0, ...array_map(static fn(array $row): float => (float) ($row['rev'] ?? 0), $productRows ?: [['rev' => 0]]));
$productsSold = count($productRows);
$totalProductUnits = array_reduce($productRows, static fn(float $carry, array $row): float => $carry + (float) $row['qty'], 0.0);
$totalProductRevenue = array_reduce($productRows, static fn(float $carry, array $row): float => $carry + (float) $row['rev'], 0.0);
$avgProductRevenue = $productsSold > 0 ? $totalProductRevenue / $productsSold : 0.0;
$topProduct = $productRows[0] ?? null;
$queryBase = [
    'range' => $filters['range'],
    'from' => $filters['from'],
    'to' => $filters['to'],
    'status' => $filters['status'],
    'mode' => $filters['mode'],
    'payment' => $filters['payment'],
];
$inventoryQueryBase = array_merge($queryBase, [
    'tab' => 'inventory',
    'inv_search' => $inventorySearch,
    'inv_category' => $inventoryCategory,
    'inv_stock' => $inventoryStock,
    'inv_sort' => $inventorySort,
    'inv_dir' => $inventoryDir,
    'inv_live' => $inventoryLiveRequested ? '1' : '0',
]);

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module cor-wrap">
    <style>
        :root {
            --cor-olive: #A8CA19;
            --cor-amber: #F07420;
            --cor-orange-red: #AB3619;
            --cor-red: #BB1B21;
            --cor-burgundy: #721B1A;
            --cor-cream: #FDF6EE;
            --cor-surface: #FFFFFF;
            --cor-border: #EDE3D8;
            --cor-text: #2C1810;
            --cor-text-mid: #6B4C3B;
            --cor-text-light: #A08070;
        }
        .cor-wrap {
            background: #FDF6EE;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 13px;
            color: #2C1810;
            --cor-olive: #A8CA19;
            --cor-amber: #F07420;
            --cor-orange-red: #AB3619;
            --cor-red: #BB1B21;
            --cor-burgundy: #721B1A;
            --cor-cream: #FDF6EE;
            --cor-surface: #FFFFFF;
            --cor-border: #EDE3D8;
            --cor-text: #2C1810;
            --cor-text-mid: #6B4C3B;
            --cor-text-light: #A08070;
        }
        .cor-wrap .module-header { margin-bottom: 14px; }
        .cor-wrap .page-eyebrow { margin: 0; color: var(--cor-orange-red); font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .cor-wrap h1 { margin: 2px 0 6px; color: var(--cor-burgundy); font-size: 26px; line-height: 1.05; }
        .cor-wrap .page-subtitle { margin: 0; color: var(--cor-text-mid); font-size: 13px; }
        .orders-filter-panel { width: 100%; margin: 24px 0 18px; border: 1px solid var(--cor-border); border-radius: 12px; background: #fff; box-shadow: 0 10px 26px rgba(114,27,26,.06); overflow: visible; }
        .orders-filter-header { width: 100%; min-height: 42px; padding: 0 14px; border: 0; border-radius: 12px; background: #fff; color: var(--cor-burgundy); display: flex; align-items: center; justify-content: space-between; gap: 12px; font-family: inherit; cursor: pointer; box-sizing: border-box; transition: background-color .16s ease; }
        .orders-filter-header:hover { background: rgba(240,116,32,.045); }
        .orders-filter-header-left, .orders-filter-header-right { display: inline-flex; align-items: center; gap: 8px; }
        .orders-filter-icon { width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; color: var(--cor-orange-red); }
        .orders-filter-icon svg, .orders-filter-chevron { width: 14px; height: 14px; }
        .orders-filter-title { color: var(--cor-burgundy); font-size: 13px; line-height: 1; font-weight: 600; }
        .orders-filter-state, .orders-filter-active-count { min-height: 22px; padding: 0 9px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; color: var(--cor-orange-red); font-size: 10px; line-height: 1; font-weight: 600; }
        .orders-filter-state { background: rgba(240,116,32,.08); }
        .orders-filter-active-count { background: rgba(168,202,25,.12); color: var(--cor-burgundy); }
        .orders-filter-chevron { color: var(--cor-orange-red); transition: transform .18s ease; }
        .orders-filter-panel:not(.is-collapsed) .orders-filter-chevron { transform: rotate(180deg); }
        .orders-filter-body { padding: 14px; border-top: 1px solid var(--cor-border); background: #fff; box-sizing: border-box; animation: ordersFilterIn .16s ease-out; }
        @keyframes ordersFilterIn { from { opacity: .65; transform: translateY(-3px); } to { opacity: 1; transform: translateY(0); } }
        .orders-filter-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; align-items: end; }
        .orders-filter-field { min-width: 0; }
        .orders-filter-field-label { display: block; margin: 0 0 6px; color: var(--cor-burgundy); font-size: 10px; line-height: 1; font-weight: 600; letter-spacing: .025em; text-transform: uppercase; }
        .orders-filter-panel .portal-custom-select-trigger, .orders-filter-input, .orders-filter-panel .portal-date-input { height: 32px; min-height: 32px; border: 1px solid rgba(171,54,25,.22); border-radius: 9px; font-size: 12px; font-weight: 400; }
        .orders-filter-panel .portal-custom-select-menu { z-index: 10050; }
        .orders-filter-panel .portal-custom-select-chevron { width: 13px; height: 13px; color: var(--cor-orange-red); }
        .orders-filter-input { width: 100%; padding: 0 11px; background: #fff; color: var(--cor-text); font-family: inherit; box-sizing: border-box; }
        .orders-filter-input:focus { outline: 0; border-color: var(--cor-orange-red); box-shadow: 0 0 0 2px rgba(171,54,25,.10); }
        .orders-custom-dates { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; grid-column: span 2; }
        .orders-filter-panel .portal-date-input { padding: 0 38px 0 11px; }
        .orders-filter-actions { margin-top: 12px; display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; }
        .orders-filter-action { height: 32px; min-height: 32px; padding: 0 12px; border: 1px solid rgba(171,54,25,.24); border-radius: 9px; background: #fff; color: var(--cor-orange-red); display: inline-flex; align-items: center; justify-content: center; gap: 7px; font-family: inherit; font-size: 11px; line-height: 1; font-weight: 700; text-decoration: none; cursor: pointer; box-sizing: border-box; }
        .orders-filter-action.primary { border-color: var(--cor-orange-red); background: var(--cor-orange-red); color: #fff; }
        .orders-filter-action:hover { transform: translateY(-1px); }
        .cor-stat-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; margin-bottom: 16px; }
        .cor-stat-card { border: 1px solid var(--cor-border); border-radius: 10px; padding: 14px 16px; background: var(--cor-surface); box-shadow: 0 2px 8px rgba(44,24,16,.06); transition: transform 180ms ease; }
        .cor-stat-card:hover { transform: translateY(-2px); }
        .cor-stat-card.total-orders, .cor-stat-card.completed { border-left: 4px solid var(--cor-olive); }
        .cor-stat-card.total-revenue { border-left: 4px solid var(--cor-burgundy); }
        .cor-stat-card.aov, .cor-stat-card.processing { border-left: 4px solid var(--cor-orange-red); }
        .cor-stat-card.pending-stat, .cor-stat-card.vat { border-left: 4px solid var(--cor-amber); }
        .cor-stat-card.refunds { border-left: 4px solid var(--cor-red); }
        .cor-stat-card.monthly { border-left: 4px solid var(--cor-burgundy); }
        .cor-stat-card .s-title { margin-bottom: 6px; color: var(--cor-text-mid); font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .cor-stat-card .s-num { color: var(--cor-text); font-size: 24px; font-weight: 800; line-height: 1; }
        .cor-stat-card .s-sub { margin-top: 4px; color: var(--cor-text-light); font-size: 11px; }
        .cor-summary-stat-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 10px; }
        .cor-top-card { padding: 16px 18px; border: 1px solid var(--cor-border); border-radius: 10px; background: var(--cor-surface); box-shadow: 0 2px 8px rgba(44,24,16,.06); }
        .cor-top-card .tc-label { margin-bottom: 6px; color: var(--cor-text-mid); font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .cor-top-card .tc-value { color: var(--cor-orange-red); font-size: 22px; font-weight: 800; line-height: 1; }
        .cor-top-card .tc-sub { margin-top: 4px; color: var(--cor-text-light); font-size: 11px; }
        .cor-summary-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 16px; }
        .cor-btn-daily { height: 32px; padding: 0 16px; border: 0; border-radius: 7px; background: var(--cor-burgundy); color: #fff; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .cor-btn-daily:hover { background: var(--cor-orange-red); }
        .cor-btn-export-sm { height: 32px; padding: 0 12px; border: 1px solid var(--cor-border); border-radius: 6px; background: var(--cor-surface); color: var(--cor-text-mid); font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; }
        .cor-btn-export-sm:hover { border-color: var(--cor-orange-red); background: #f5ece8; }
        .cor-pay-cards { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .cor-pay-card { min-width: 130px; padding: 10px 14px; border: 1px solid var(--cor-border); border-radius: 8px; background: var(--cor-surface); box-shadow: 0 1px 4px rgba(44,24,16,.05); text-decoration: none; transition: border-color 150ms ease, box-shadow 150ms ease; }
        .cor-pay-card:hover { border-color: var(--cor-orange-red); box-shadow: 0 4px 12px rgba(44,24,16,.10); }
        .cor-pay-card .pc-method { margin-bottom: 4px; color: var(--cor-text-mid); font-size: 11px; font-weight: 700; }
        .cor-pay-card .pc-amount { color: var(--cor-text); font-size: 18px; font-weight: 800; line-height: 1; }
        .cor-pay-card .pc-link { margin-top: 3px; color: var(--cor-orange-red); font-size: 10px; }
        .cor-pay-card.refunds-card { cursor: default; }
        .cor-pay-card.refunds-card .pc-amount { color: var(--cor-red); }
        .cor-tabs-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 16px; border-bottom: 2px solid var(--cor-border); }
        .cor-tabs { display: flex; gap: 0; min-width: max-content; border-bottom: 0; white-space: nowrap; }
        .cor-tab { flex: 0 0 auto; height: 38px; margin-bottom: -2px; padding: 0 16px; border: 0; border-bottom: 3px solid transparent; background: transparent; color: var(--cor-text-mid); font-size: 12px; font-weight: 600; cursor: pointer; }
        .cor-tab:hover { color: var(--cor-orange-red); }
        .cor-tab.active { border-bottom-color: var(--cor-orange-red); color: var(--cor-burgundy); }
        .cor-tab-content { display: none; }
        .cor-tab-content.active { display: block; }
        .cor-report-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .cor-sales-breakdowns { margin-top: 14px; grid-template-columns: 1fr; }
        .cor-two-col { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 14px; }
        .cor-report-card, .cor-chart-card { overflow: hidden; margin-bottom: 14px; border: 1px solid var(--cor-border); border-radius: 10px; background: var(--cor-surface); box-shadow: 0 2px 6px rgba(44,24,16,.05); }
        .cor-report-card .card-head, .cor-chart-card .card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--cor-border); }
        .cor-report-card h3, .cor-chart-card h3 { margin: 0; color: var(--cor-burgundy); font-size: 13px; font-weight: 700; }
        .cor-report-card table { width: 100%; border-collapse: collapse; }
        .cor-table-wrap { overflow-x: auto; }
        .cor-report-card th, .cor-orders-table th { height: 34px; padding: 0 14px; border-bottom: 2px solid var(--cor-border); background: var(--cor-cream); color: var(--cor-burgundy); font-size: 11px; font-weight: 700; letter-spacing: .05em; text-align: left; text-transform: uppercase; white-space: nowrap; }
        .cor-report-card td, .cor-orders-table td { height: 38px; padding: 0 14px; border-bottom: 1px solid var(--cor-border); color: var(--cor-text); font-size: 13px; vertical-align: middle; }
        .cor-report-card tr:last-child td, .cor-orders-table tr:last-child td { border-bottom: 0; }
        .cor-report-card tr:hover td, .cor-orders-table tr:hover td { background: #fdf3ea; }
        .cor-bar-wrap { display: flex; align-items: center; gap: 8px; min-width: 160px; }
        .cor-bar-track { flex: 1; min-width: 60px; height: 6px; overflow: hidden; border-radius: 3px; background: var(--cor-border); }
        .cor-bar-fill { display: block; height: 100%; border-radius: 3px; background: var(--cor-orange-red); }
        .cor-pct { min-width: 36px; color: var(--cor-text-mid); font-size: 11px; text-align: right; }
        .cor-pill { display: inline-flex; align-items: center; height: 21px; padding: 0 8px; border-radius: 5px; font-size: 10px; font-weight: 700; white-space: nowrap; }
        .cor-status-completed { background: var(--cor-olive); color: #2C1810; }
        .cor-status-in-progress, .cor-status-packed, .cor-status-processing { background: var(--cor-amber); color: #fff; }
        .cor-status-new-order, .cor-status-assigned, .cor-status-pending { background: #c8c1b8; color: #fff; }
        .cor-status-error-logged, .cor-status-correction-required, .cor-status-refunded { background: var(--cor-red); color: #fff; }
        .pay-eft { background: #5c4ab0; color: #fff; }
        .pay-cash { background: #7a7a7a; color: #fff; }
        .pay-easywallet { background: #7B68EE; color: #fff; }
        .pay-swipe, .pay-card { background: #2C1810; color: #fff; }
        .pay-bluewallet { background: #00838F; color: #fff; }
        .pay-fnb { background: #1B5E20; color: #fff; }
        .pay-pay2cell { background: var(--cor-red); color: #fff; }
        .pay-other { background: var(--cor-border); color: var(--cor-text-mid); }
        .mode-delivery { background: #b5a280; color: #fff; }
        .mode-collection { background: #7a8c4f; color: #fff; }
        .mode-courier { background: #5c3a1e; color: #fff; }
        .mode-walk-in, .mode-walkin { background: var(--cor-border); color: var(--cor-text-mid); }
        .cor-refund-amount, .cor-profit-negative, .cor-mom-down { color: var(--cor-red); font-weight: 700; }
        .cor-vat-amount { color: var(--cor-amber); font-weight: 700; }
        .cor-profit-positive, .cor-mom-up { color: var(--cor-olive); font-weight: 700; }
        .inv-instock, .inv-lowstock, .inv-outstock { height: 20px; padding: 0 8px; border-radius: 4px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; }
        .inv-instock { border: 1px solid var(--cor-olive); background: #f3fae0; color: #6f8c10; }
        .inv-lowstock { border: 1px solid var(--cor-amber); background: #fef5e7; color: var(--cor-amber); }
        .inv-outstock { border: 1px solid var(--cor-red); background: #fde8e8; color: var(--cor-red); }
        .cor-rank { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: var(--cor-border); color: var(--cor-text-mid); font-size: 11px; font-weight: 800; }
        .cor-rank-1 { background: var(--cor-amber); color: #fff; }
        .cor-rank-2 { background: var(--cor-orange-red); color: #fff; }
        .cor-rank-3 { background: var(--cor-red); color: #fff; }
        .cor-products-table th { height: 36px !important; padding: 0 14px !important; border-bottom: 2px solid var(--cor-border) !important; background: var(--cor-cream) !important; color: var(--cor-burgundy) !important; font-size: 11px !important; font-weight: 800 !important; letter-spacing: .05em !important; text-transform: uppercase !important; white-space: nowrap; }
        .cor-products-table td { height: 40px !important; padding: 0 14px !important; border-bottom: 1px solid var(--cor-border) !important; color: var(--cor-text) !important; font-size: 13px !important; vertical-align: middle !important; }
        .cor-products-table tr:last-child td { border-bottom: 0 !important; }
        .cor-products-table tr:hover td { background: #fdf3ea !important; }
        .cor-products-table tr.top-rank td:first-child { border-left: 3px solid var(--cor-amber); }
        .prod-name { font-weight: 400; }
        .prod-top { color: var(--cor-burgundy) !important; font-weight: 700 !important; }
        .prod-revenue { color: var(--cor-text) !important; font-weight: 800 !important; }
        .cor-pct-cell { display: flex; align-items: center; gap: 8px; }
        .cor-pct-bar-wrap { flex: 1; min-width: 80px; max-width: 200px; height: 8px; overflow: hidden; border-radius: 4px; background: var(--cor-border); }
        .cor-pct-bar { display: block; height: 100%; min-width: 3px; border-radius: 4px; background: var(--cor-olive); transition: width 600ms ease; }
        .cor-pct-label { min-width: 36px; color: var(--cor-text-mid); font-size: 11px; font-weight: 700; text-align: right; }
        .cor-top-card .tc-value { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .del-breakdown-top { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 30px; }
        .del-pie-wrap { flex: 0 0 auto; }
        .del-legend { min-width: 260px; flex: 1; }
        .del-legend-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 12px; }
        .del-legend-dot { width: 12px; height: 12px; border-radius: 50%; flex: 0 0 auto; }
        .del-legend-name { flex: 1; color: var(--cor-text); }
        .del-legend-amt { min-width: 90px; color: var(--cor-text); font-weight: 700; text-align: right; }
        .del-legend-pct { min-width: 36px; color: var(--cor-text-mid); font-size: 11px; text-align: right; }
        .del-hbar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .del-hbar-label { min-width: 130px; color: var(--cor-text); font-size: 12px; text-align: right; }
        .del-hbar-track { flex: 1; height: 18px; overflow: hidden; border-radius: 4px; background: var(--cor-border); }
        .del-hbar-fill { height: 100%; min-width: 4px; border-radius: 4px; transition: width 600ms ease; }
        .del-hbar-val { min-width: 90px; color: var(--cor-text); font-size: 12px; font-weight: 800; }
        .del-table-tools { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--cor-border); }
        .del-table-tools input, .del-table-tools select { height: 32px; padding: 0 10px; border: 1px solid var(--cor-border); border-radius: 6px; background: var(--cor-surface); color: var(--cor-text); font-size: 12px; }
        .del-table-tools input { flex: 1; min-width: 180px; }
        .del-count { color: var(--cor-text-mid); font-size: 12px; white-space: nowrap; }
        .vat-period-heading { margin: 0 0 14px; color: var(--cor-burgundy); font-size: 15px; font-weight: 800; }
        .vat-second-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
        .vat-breakdown-card { display: flex; flex-wrap: wrap; align-items: center; gap: 30px; padding: 18px; }
        .vat-net-row td { background: #f3fae0 !important; font-weight: 800; }
        .mon-group-header td { padding: 8px 14px !important; border-top: 2px solid var(--cor-border) !important; border-bottom: 1px solid var(--cor-border) !important; background: var(--cor-cream) !important; }
        .mon-group-label { color: var(--cor-orange-red) !important; font-size: 11px !important; font-weight: 800 !important; letter-spacing: .06em !important; text-transform: uppercase !important; }
        .mon-notes { color: var(--cor-text-light) !important; font-size: 11px !important; text-align: right !important; }
        .mon-highlight td { padding-top: 10px !important; padding-bottom: 10px !important; background: #f3fae0 !important; }
        .inv-stat-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 10px; }
        .inv-stat-row-two { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
        .inv-visual-grid { display: grid; grid-template-columns: minmax(280px, 0.9fr) minmax(360px, 1.8fr); gap: 14px; margin-bottom: 14px; }
        .inv-health-card { display: flex; align-items: center; justify-content: center; gap: 24px; min-height: 250px; padding: 18px; }
        .inv-health-legend { min-width: 190px; }
        .inv-health-label { color: var(--cor-burgundy); font-size: 13px; font-weight: 800; margin-bottom: 12px; }
        #tab-inventory .cor-report-card { width: 100%; }
        #tab-inventory .cor-products-table { min-width: 1180px; table-layout: fixed; }
        #tab-inventory .cor-products-table th, #tab-inventory .cor-products-table td { padding: 8px 10px; vertical-align: middle; }
        .inv-tools { padding: 12px 16px; border-bottom: 1px solid var(--cor-border); }
        .inv-tools-row { display: grid; grid-template-columns: minmax(260px, 1.4fr) minmax(150px, .8fr) minmax(150px, .75fr) auto minmax(140px, .7fr) auto; align-items: center; gap: 8px; }
        .inv-tools input, .inv-tools select { height: 36px; padding: 0 10px; border: 1px solid var(--cor-border); border-radius: 6px; background: var(--cor-surface); color: var(--cor-text); font-size: 12px; }
        .inv-tools input { min-width: 0; }
        .inv-sort-btn { height: 36px; padding: 0 12px; border: 1px solid var(--cor-border); border-radius: 6px; background: var(--cor-surface); color: var(--cor-text-mid); font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; }
        .inv-refresh-btn { height: 36px; padding: 0 14px; border: 0; border-radius: 6px; background: var(--cor-orange-red); color: #fff; font-size: 12px; font-weight: 800; cursor: pointer; }
        .inv-count { margin-top: 8px; color: var(--cor-text-mid); font-size: 11px; }
        .inv-sku-chip { display: inline-block; padding: 2px 6px; border-radius: 3px; background: var(--cor-border); color: var(--cor-text-mid); font-size: 10px; font-weight: 700; }
        .inv-cost-box { display: inline-block; min-width: 58px; padding: 4px 8px; border: 1px solid var(--cor-border); border-radius: 5px; background: #fff; color: var(--cor-text); font-size: 12px; text-align: right; }
        .inv-cost-input { width: 78px; height: 28px; padding: 0 8px; border: 1px solid var(--cor-border); border-radius: 5px; background: #fff; color: var(--cor-text); font-size: 12px; text-align: right; }
        .inv-cost-input:focus { outline: none; border-color: var(--cor-orange-red); box-shadow: 0 0 0 2px rgba(171,54,25,.12); }
        .inv-cost-input.is-saving { border-color: #d9b08e; background: #fff7ef; }
        .inv-cost-input.is-saved { border-color: #8bbf38; background: #f7fbea; }
        .inv-cost-input.is-error { border-color: var(--cor-red); background: #fff5f5; }
        .inv-table-wrap { overflow-x: auto; max-height: 620px; overflow-y: auto; }
        .cor-data-message { margin: 0 0 14px; padding: 10px 12px; border-radius: 7px; font-size: 12px; font-weight: 700; }
        .cor-data-error { border: 1px solid #f2b8b8; background: #fff5f5; color: var(--cor-red); }
        .cor-data-ok { border: 1px solid #dcebb0; background: #f7fbea; color: #6f8c10; }
        .cor-data-loading { border: 1px solid #ead8c9; background: #fff7ef; color: var(--cor-burgundy); }
        .cor-loading-banner { margin: 0 0 14px; padding: 10px 12px; border: 1px solid #ead8c9; border-radius: 7px; background: #fff7ef; color: var(--cor-burgundy); font-size: 12px; font-weight: 700; }
        .inv-row-low td { background: #fff9f0 !important; }
        .inv-row-out td { background: #fff5f5 !important; }
        .inv-qty-in { color: #6f8c10 !important; font-weight: 800 !important; }
        .inv-qty-low { color: var(--cor-amber) !important; font-weight: 800 !important; }
        .inv-qty-out { color: var(--cor-red) !important; font-weight: 800 !important; }
        .cor-chart-body { height: 260px; padding: 16px; }
        .cor-orders-wrap { max-height: 520px; overflow: auto; }
        .cor-orders-table { width: 100%; border-collapse: collapse; }
        .cor-orders-table th { position: sticky; top: 0; z-index: 2; }
        .cor-pagination { display: flex; align-items: center; justify-content: flex-end; gap: 4px; padding: 12px 16px; border-top: 1px solid var(--cor-border); }
        .cor-page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px; padding: 0 8px; border: 1px solid var(--cor-border); border-radius: 5px; background: var(--cor-surface); color: var(--cor-text-mid); font-size: 12px; text-decoration: none; }
        .cor-page-btn.active { border-color: var(--cor-orange-red); background: var(--cor-orange-red); color: #fff; }
        .cor-empty { padding: 16px 18px; color: var(--cor-text-mid); font-size: 13px; }
        @media (max-width: 1100px) { .cor-stat-grid { grid-template-columns: repeat(3, 1fr); } .cor-report-grid, .cor-two-col, .inv-visual-grid { grid-template-columns: 1fr; } }
        @media (max-width: 900px) { .cor-summary-stat-grid, .inv-stat-row, .inv-stat-row-two { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 1400px) { .orders-filter-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 900px) { .orders-filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 767px) { .cor-stat-grid, .cor-summary-stat-grid { grid-template-columns: repeat(2, 1fr); } .cor-tab { padding: 0 12px; font-size: 11px; } .orders-filter-panel { margin-top: 18px; } .del-breakdown-top, .vat-breakdown-card, .inv-health-card { flex-direction: column; align-items: flex-start; } .del-hbar-label { min-width: 80px; font-size: 11px; } .del-table-tools, .inv-tools-row { align-items: stretch; flex-direction: column; } .inv-tools-row { display: grid; grid-template-columns: 1fr; } .del-table-tools input, .del-table-tools select, .inv-tools input, .inv-tools select, .inv-sort-btn, .inv-refresh-btn { width: 100%; } }
        @media (max-width: 600px) { .orders-filter-grid { grid-template-columns: 1fr; } .orders-custom-dates { grid-template-columns: 1fr; grid-column: auto; } .orders-filter-actions > * { flex: 1 1 auto; } }
        @media (max-width: 500px) { .cor-summary-stat-grid, .vat-second-stats, .inv-stat-row, .inv-stat-row-two { grid-template-columns: 1fr; } }
    </style>

    <section class="module-header">
        <div>
            <p class="page-eyebrow">Sales Operations</p>
            <h1>Customer Orders Report</h1>
        </div>
    </section>

    <?php if (!$ready): ?>
        <?php ops_setup_notice(); ?>
    <?php endif; ?>

    <?php $activeFilterCount = (int) ($filters['range'] !== 'month') + (int) ($filters['status'] !== 'all') + (int) ($filters['mode'] !== 'all') + (int) ($filters['payment'] !== 'all'); ?>
    <div class="cor-loading-banner" data-cor-loading hidden>Loading website data...</div>
    <?php if ($reportSyncError): ?>
        <div class="cor-data-message cor-data-error">Could not connect to WooCommerce: <?= cor_e($reportSyncError) ?></div>
    <?php endif; ?>
    <section class="orders-filter-panel is-collapsed" data-orders-filter-panel data-portal-view-filter>
      <button type="button" class="orders-filter-header" data-orders-filter-toggle aria-expanded="false" aria-controls="orders-filter-body">
        <span class="orders-filter-header-left"><span class="orders-filter-icon"><i data-lucide="sliders-horizontal" aria-hidden="true"></i></span><span class="orders-filter-title">Filters</span><?php if ($activeFilterCount > 0): ?><span class="orders-filter-active-count"><?= $activeFilterCount ?> active</span><?php endif; ?></span>
        <span class="orders-filter-header-right"><span class="orders-filter-state" data-orders-filter-state>Collapsed</span><svg class="orders-filter-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="M6 8l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
      </button>
      <div id="orders-filter-body" class="orders-filter-body" data-orders-filter-body hidden>
    <form method="get" data-cor-filter>
        <input type="hidden" name="range" value="<?= cor_e($filters['range']) ?>" data-cor-range>
        <input type="hidden" name="tab" value="<?= cor_e($filters['tab']) ?>" data-cor-tab-input>
        <div class="orders-filter-grid">
          <label class="orders-filter-field"><span class="orders-filter-field-label">Date range</span><select data-orders-range data-portal-custom-select>
            <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'Year', 'custom' => 'Custom'] as $key => $label): ?><option value="<?= cor_e($key) ?>" <?= $filters['range'] === $key ? 'selected' : '' ?>><?= cor_e($label) ?></option><?php endforeach; ?>
          </select></label>
          <label class="orders-filter-field"><span class="orders-filter-field-label">Status</span><select name="status" data-portal-custom-select>
                <option value="all">All Statuses</option>
                <?php foreach (OPS_ORDER_STATUSES as $key => $label): ?>
                    <option value="<?= cor_e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= cor_e($label) ?></option>
                <?php endforeach; ?>
            </select></label>
          <label class="orders-filter-field"><span class="orders-filter-field-label">Mode</span><select name="mode" data-portal-custom-select>
                <option value="all">All Modes</option>
                <option value="delivery" <?= $filters['mode'] === 'delivery' ? 'selected' : '' ?>>Delivery</option>
                <option value="collection" <?= $filters['mode'] === 'collection' ? 'selected' : '' ?>>Collection</option>
                <option value="courier" <?= $filters['mode'] === 'courier' ? 'selected' : '' ?>>Courier</option>
            </select></label>
          <label class="orders-filter-field"><span class="orders-filter-field-label">Payment</span><select name="payment" data-portal-custom-select>
                <option value="all">All Payments</option>
                <?php foreach ($paymentOptions as $payment): ?>
                    <option value="<?= cor_e($payment) ?>" <?= $filters['payment'] === $payment ? 'selected' : '' ?>><?= cor_e($payment) ?></option>
                <?php endforeach; ?>
            </select></label>
          <div class="orders-custom-dates" data-custom-dates style="<?= $filters['range'] !== 'custom' ? 'display:none' : '' ?>">
            <label class="orders-filter-field"><span class="orders-filter-field-label">Date from</span><span class="portal-date-field" data-portal-date-field><input class="portal-date-input" type="text" data-submit-target="#orders-date-from" placeholder="Select date"><input id="orders-date-from" type="hidden" name="from" value="<?= cor_e($filters['from']) ?>"><button type="button" class="portal-date-trigger" aria-label="Open start date"><i data-lucide="calendar-days"></i></button></span></label>
            <label class="orders-filter-field"><span class="orders-filter-field-label">Date to</span><span class="portal-date-field" data-portal-date-field><input class="portal-date-input" type="text" data-submit-target="#orders-date-to" placeholder="Select date"><input id="orders-date-to" type="hidden" name="to" value="<?= cor_e($filters['to']) ?>"><button type="button" class="portal-date-trigger" aria-label="Open end date"><i data-lucide="calendar-days"></i></button></span></label>
          </div>
        </div>
        <div class="orders-filter-actions"><a class="orders-filter-action" href="?<?= cor_e(http_build_query(['tab' => $filters['tab']])) ?>">Clear filters</a><button class="orders-filter-action primary" type="submit"><i data-lucide="refresh-cw" aria-hidden="true"></i>Refresh</button></div>
    </form>
      </div>
    </section>

    <div class="cor-tabs-wrap">
    <nav class="cor-tabs" aria-label="Report tabs">
        <?php
        $tabs = [
            'sales' => 'Sales Summary',
            'products' => 'Products',
            'delivery' => 'Delivery',
            'vat' => 'VAT',
            'monthly' => 'Monthly',
            'inventory' => 'Inventory',
            'orders' => 'All Orders',
        ];
        foreach ($tabs as $key => $label):
        ?>
            <button class="cor-tab <?= $filters['tab'] === $key ? 'active' : '' ?>" type="button" data-tab="<?= cor_e($key) ?>"><?= cor_e($label) ?></button>
        <?php endforeach; ?>
    </nav>
    </div>

    <section id="tab-sales" class="cor-tab-content <?= $filters['tab'] === 'sales' ? 'active' : '' ?>">
        <section class="cor-summary-stat-grid" aria-label="Sales summary">
            <article class="cor-top-card">
                <div class="tc-label">Total Sales</div>
                <div class="tc-value"><?= cor_money($summary['total_revenue']) ?></div>
                <div class="tc-sub"><?= number_format($summary['total_orders']) ?> orders</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Tax Collected</div>
                <div class="tc-value"><?= cor_money($summary['tax_collected'] > 0 ? $summary['tax_collected'] : ($summary['total_revenue'] * (0.15 / 1.15))) ?></div>
                <div class="tc-sub">15% VAT included</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Discounts</div>
                <div class="tc-value"><?= cor_money($summary['discounts']) ?></div>
                <div class="tc-sub">&nbsp;</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Avg Order</div>
                <div class="tc-value"><?= cor_money($summary['aov']) ?></div>
                <div class="tc-sub">per transaction</div>
            </article>
        </section>

        <section class="cor-pay-cards" aria-label="Payment quick cards">
            <?php foreach ($paymentRows as $row): ?>
                <a class="cor-pay-card" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'orders', 'payment' => $row['label']]))) ?>">
                    <div class="pc-method"><?= cor_e($row['label']) ?></div>
                    <div class="pc-amount"><?= cor_money($row['rev']) ?></div>
                    <div class="pc-link">Click to see orders</div>
                </a>
            <?php endforeach; ?>
            <article class="cor-pay-card refunds-card">
                <div class="pc-method">Refunds</div>
                <div class="pc-amount"><?= cor_money($refundSummary['amount']) ?></div>
                <div class="pc-link">&nbsp;</div>
            </article>
        </section>

        <section class="cor-summary-actions" aria-label="Summary actions">
            <a class="cor-btn-daily" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'sales']))) ?>">Daily Sales Summary</a>
            <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query($queryBase + ['export' => 'csv'])) ?>">CSV</a>
            <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query($queryBase + ['export' => 'excel'])) ?>">Excel</a>
            <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
        </section>

        <div class="cor-two-col">
            <article class="cor-report-card">
                <div class="card-head"><h3>By Payment Method</h3><span><?= number_format(count($paymentRows)) ?> methods</span></div>
                <div class="cor-table-wrap">
                    <table>
                        <thead><tr><th>Method</th><th>Orders</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($paymentRows as $row): $pct = $summary['total_revenue'] > 0 ? ((float) $row['rev'] / $summary['total_revenue']) * 100 : 0; ?>
                            <tr><td><span class="cor-pill pay-<?= cor_e(cor_slug($row['label'])) ?>"><?= cor_e($row['label']) ?></span></td><td><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['rev']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$paymentRows): ?><tr><td colspan="3">No records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="cor-report-card">
                <div class="card-head"><h3>By Cashier</h3><span>Packed by / assigned staff</span></div>
                <div class="cor-table-wrap">
                    <table>
                        <thead><tr><th>Cashier</th><th>Orders</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($staffRows as $row): $pct = $summary['total_orders'] > 0 ? ((int) $row['cnt'] / $summary['total_orders']) * 100 : 0; ?>
                            <tr><td><?= cor_e($row['label']) ?></td><td><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['rev']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$staffRows): ?><tr><td colspan="3">No records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>

        <div class="cor-two-col">
            <article class="cor-report-card">
                <div class="card-head"><h3>By Status</h3><span><?= number_format(count($statusRows)) ?> statuses</span></div>
                <div class="cor-table-wrap">
                    <table>
                        <thead><tr><th>Status</th><th>Orders</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($statusRows as $row): ?>
                            <tr><td><span class="cor-pill cor-status-<?= cor_e(cor_slug($row['label'])) ?>"><?= cor_e(cor_order_status_label((string) $row['label'])) ?></span></td><td><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['rev']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$statusRows): ?><tr><td colspan="3">No records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="cor-chart-card">
                <div class="card-head"><h3>Daily Sales (last 14 days)</h3><span>Revenue trend</span></div>
                <div class="cor-chart-body"><canvas id="daily14Chart"></canvas></div>
                <?php if (!$daily14Rows): ?><div class="cor-empty">No daily sales found.</div><?php endif; ?>
            </article>
        </div>
    </section>

    <section id="tab-products" class="cor-tab-content <?= $filters['tab'] === 'products' ? 'active' : '' ?>">
        <section class="cor-summary-stat-grid" aria-label="Product sales summary">
            <article class="cor-top-card">
                <div class="tc-label">Products Sold</div>
                <div class="tc-value"><?= number_format($productsSold) ?></div>
                <div class="tc-sub">unique SKUs</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Top Product</div>
                <div class="tc-value" style="font-size:16px;"><?= $topProduct ? cor_e(cor_short_text($topProduct['product_name'], 30)) : '-' ?></div>
                <div class="tc-sub"><?= $topProduct ? cor_money($topProduct['rev']) : '&nbsp;' ?></div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Total Units</div>
                <div class="tc-value"><?= number_format($totalProductUnits, 2) ?></div>
                <div class="tc-sub">&nbsp;</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Avg Revenue</div>
                <div class="tc-value"><?= cor_money($avgProductRevenue) ?></div>
                <div class="tc-sub">per product</div>
            </article>
        </section>

        <section class="cor-summary-actions" aria-label="Product export actions">
            <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'products', 'export' => 'csv']))) ?>">↓CSV</a>
            <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'products', 'export' => 'excel']))) ?>">↓Excel</a>
            <button class="cor-btn-export-sm" type="button" onclick="window.print()">↓PDF</button>
        </section>

        <article class="cor-report-card">
            <div class="card-head"><h3>Revenue per Product</h3><span><?= cor_e($filters['from']) ?> &rarr; <?= cor_e($filters['to']) ?></span></div>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th style="width:44px;">#</th><th>Product</th><th style="width:120px;">Units Sold</th><th style="width:140px;">Revenue</th><th style="width:280px;">% of Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($productRows as $index => $row): $rank = $index + 1; $pct = $totalProductRevenue > 0 ? ((float) $row['rev'] / $totalProductRevenue) * 100 : 0; ?>
                        <tr class="<?= $rank <= 3 ? 'top-rank' : '' ?>">
                            <td><span class="cor-rank cor-rank-<?= $rank <= 3 ? $rank : 'n' ?>"><?= number_format($rank) ?></span></td>
                            <td class="prod-name <?= $rank <= 3 ? 'prod-top' : '' ?>"><?= cor_e($row['product_name']) ?></td>
                            <td><?= number_format((float) $row['qty'], 2) ?></td>
                            <td class="prod-revenue"><?= cor_money($row['rev']) ?></td>
                            <td>
                                <div class="cor-pct-cell">
                                    <div class="cor-pct-bar-wrap"><span class="cor-pct-bar" style="width:<?= cor_e($pct) ?>%"></span></div>
                                    <span class="cor-pct-label"><?= cor_pct($pct) ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$productRows): ?><tr><td colspan="5" style="height:auto;padding:24px !important;text-align:center;color:var(--cor-text-light) !important;">No product sales found for this period.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section id="tab-delivery" class="cor-tab-content <?= $filters['tab'] === 'delivery' ? 'active' : '' ?>">
        <?php $deliveryColours = ['#A8CA19', '#AB3619', '#F07420', '#BB1B21', '#721B1A', '#6B4C3B', '#EDE3D8']; ?>
        <section class="cor-summary-stat-grid" aria-label="Delivery summary">
            <article class="cor-top-card">
                <div class="tc-label">Total Delivery Fees</div>
                <div class="tc-value"><?= cor_money($deliverySummary['fees']) ?></div>
                <div class="tc-sub">collected this period</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Orders With Delivery</div>
                <div class="tc-value"><?= number_format($deliverySummary['orders']) ?></div>
                <div class="tc-sub">shipped orders</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Avg Delivery Fee</div>
                <div class="tc-value"><?= cor_money($deliverySummary['avg']) ?></div>
                <div class="tc-sub">per order</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Courier Orders</div>
                <div class="tc-value"><?= number_format($deliverySummary['courier']) ?></div>
                <div class="tc-sub">this period</div>
            </article>
        </section>

        <article class="cor-report-card">
            <div class="card-head"><h3>Delivery Type Breakdown</h3><span><?= number_format(count($deliveryRows)) ?> types</span></div>
            <div style="padding:18px;">
                <div class="del-breakdown-top">
                    <div class="del-pie-wrap">
                        <canvas id="delPieChart" width="160" height="160"></canvas>
                    </div>
                    <div class="del-legend">
                        <div style="font-size:13px;font-weight:700;color:var(--cor-burgundy);margin-bottom:10px;">Delivery by Type</div>
                        <?php foreach ($deliveryRows as $index => $row): $colour = $deliveryColours[$index % count($deliveryColours)]; $pct = $deliverySummary['total_cost'] > 0 ? ((float) $row['total_cost'] / $deliverySummary['total_cost']) * 100 : 0; ?>
                            <div class="del-legend-row">
                                <span class="del-legend-dot" style="background:<?= cor_e($colour) ?>"></span>
                                <span class="del-legend-name"><?= cor_e(ucwords((string) $row['label'])) ?></span>
                                <span class="del-legend-amt"><?= cor_money($row['total_cost']) ?></span>
                                <span class="del-legend-pct"><?= cor_pct($pct) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$deliveryRows): ?><div class="cor-empty" style="padding:0;">No delivery records found.</div><?php endif; ?>
                    </div>
                </div>
                <div style="margin-top:20px;">
                    <?php foreach ($deliveryRows as $index => $row): $colour = $deliveryColours[$index % count($deliveryColours)]; $barPct = $deliverySummary['total_cost'] > 0 ? ((float) $row['total_cost'] / $deliverySummary['total_cost']) * 100 : 0; ?>
                        <div class="del-hbar-row">
                            <div class="del-hbar-label"><?= cor_e(ucwords((string) $row['label'])) ?></div>
                            <div class="del-hbar-track"><div class="del-hbar-fill" style="width:<?= cor_e($barPct) ?>%;background:<?= cor_e($colour) ?>"></div></div>
                            <div class="del-hbar-val"><?= cor_money($row['total_cost']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>

        <article class="cor-report-card">
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Delivery Type</th><th>Orders</th><th>Total Cost</th><th>Avg Per Order</th></tr></thead>
                    <tbody>
                    <?php foreach ($deliveryRows as $row): ?>
                        <tr><td style="font-weight:600;"><?= cor_e(ucwords((string) $row['label'])) ?></td><td style="color:var(--cor-orange-red);font-weight:700;"><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['total_cost']) ?></td><td><?= cor_money($row['avg_cost']) ?></td></tr>
                    <?php endforeach; ?>
                    <tr style="border-top:2px solid var(--cor-border);font-weight:800;"><td>Total</td><td style="color:var(--cor-orange-red);"><?= number_format($deliverySummary['total_orders']) ?></td><td><?= cor_money($deliverySummary['total_cost']) ?></td><td><?= cor_money($deliverySummary['breakdown_avg']) ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'delivery', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'delivery', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>

        <article class="cor-chart-card">
            <div class="card-head"><h3>Delivery Orders by Day</h3><span>Selected period</span></div>
            <div style="height:180px;padding:16px;"><canvas id="delDayChart"></canvas></div>
            <?php if (!$deliveryDayRows): ?><div class="cor-empty">No delivery day records found.</div><?php endif; ?>
        </article>

        <article class="cor-report-card">
            <div class="card-head"><h3>Delivery Orders</h3><span><?= number_format($deliveryOrdersCount) ?> orders</span></div>
            <form class="del-table-tools" method="get">
                <?php foreach ($queryBase as $key => $value): ?><input type="hidden" name="<?= cor_e($key) ?>" value="<?= cor_e($value) ?>"><?php endforeach; ?>
                <input type="hidden" name="tab" value="delivery">
                <input type="text" name="del_search" value="<?= cor_e($deliverySearch) ?>" placeholder="Search customer or order #...">
                <select name="del_status" onchange="this.form.submit()" data-portal-custom-select>
                    <option value="all">All</option>
                    <?php foreach (OPS_ORDER_STATUSES as $key => $label): ?>
                        <option value="<?= cor_e($key) ?>" <?= $deliveryStatus === $key ? 'selected' : '' ?>><?= cor_e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="cor-btn-apply" type="submit">Search</button>
                <span class="del-count"><?= number_format($deliveryOrdersCount) ?> orders</span>
            </form>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Order #</th><th>Customer</th><th>Mobile</th><th>Delivery Type</th><th>Cost</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($deliveryOrderRows as $row): ?>
                        <tr>
                            <td style="font-weight:800;color:var(--cor-burgundy);"><?= cor_e($row['order_number']) ?></td>
                            <td style="color:var(--cor-orange-red);"><?= cor_e($row['customer_name'] ?: 'Customer') ?></td>
                            <td><?= cor_e($row['customer_contact']) ?></td>
                            <td><?= cor_e(ucwords((string) $row['order_type'])) ?></td>
                            <td><?= cor_money($row['delivery_cost']) ?></td>
                            <td><span class="cor-pill cor-status-<?= cor_e(cor_slug($row['status'])) ?>"><?= cor_e(cor_order_status_label((string) $row['status'])) ?></span></td>
                            <td><?= cor_e(date('d M Y', strtotime((string) $row['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$deliveryOrderRows): ?><tr><td colspan="7" style="height:auto;padding:24px !important;text-align:center;color:var(--cor-text-light) !important;">No delivery orders found for this period.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section id="tab-vat" class="cor-tab-content <?= $filters['tab'] === 'vat' ? 'active' : '' ?>">
        <h2 class="vat-period-heading">VAT Period Summary - <?= cor_e($filters['from']) ?> to <?= cor_e($filters['to']) ?></h2>

        <section class="cor-summary-stat-grid" aria-label="VAT period summary">
            <article class="cor-top-card">
                <div class="tc-label">Total Invoice Sales</div>
                <div class="tc-value"><?= cor_money($vatMetrics['total_sales']) ?></div>
                <div class="tc-sub"><?= number_format((int) $vatMetrics['order_count']) ?> orders</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Shipping Excluded</div>
                <div class="tc-value"><?= cor_money($vatMetrics['shipping']) ?></div>
                <div class="tc-sub">not subject to VAT</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">VATable Sales</div>
                <div class="tc-value"><?= cor_money($vatMetrics['vatable_sales']) ?></div>
                <div class="tc-sub">products only</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-amber);">
                <div class="tc-label">VAT Collected (15%)</div>
                <div class="tc-value" style="color:var(--cor-amber);"><?= cor_money($vatMetrics['vat_collected']) ?></div>
                <div class="tc-sub">on product sales</div>
            </article>
        </section>

        <section class="vat-second-stats" aria-label="VAT net summary">
            <article class="cor-top-card">
                <div class="tc-label">Sales Ex-VAT</div>
                <div class="tc-value"><?= cor_money($vatMetrics['sales_ex_vat']) ?></div>
                <div class="tc-sub">ex-VAT revenue</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-olive);">
                <div class="tc-label">Net VAT Payable</div>
                <div class="tc-value" style="color:var(--cor-olive);"><?= cor_money($vatMetrics['net_vat_payable']) ?></div>
                <div class="tc-sub"><?= $vatMetrics['refunds'] > 0 ? 'after refunds' : 'no refunds' ?></div>
            </article>
        </section>

        <article class="cor-report-card">
            <?php
            $vatBreakdownItems = [
                ['label' => 'Ex-VAT Revenue', 'amount' => $vatMetrics['sales_ex_vat'], 'colour' => '#AB3619'],
                ['label' => 'VAT Collected', 'amount' => $vatMetrics['vat_collected'], 'colour' => '#A8CA19'],
                ['label' => 'Shipping', 'amount' => $vatMetrics['shipping'], 'colour' => '#F07420'],
            ];
            $vatBreakdownTotal = array_reduce($vatBreakdownItems, static fn(float $carry, array $item): float => $carry + (float) $item['amount'], 0.0);
            ?>
            <div class="vat-breakdown-card">
                <canvas id="vatPieChart" width="160" height="160" style="flex:0 0 auto;"></canvas>
                <div class="del-legend">
                    <div style="margin-bottom:14px;color:var(--cor-burgundy);font-size:14px;font-weight:800;">VAT Breakdown</div>
                    <?php foreach ($vatBreakdownItems as $item): $pct = $vatBreakdownTotal > 0 ? ((float) $item['amount'] / $vatBreakdownTotal) * 100 : 0; ?>
                        <div class="del-legend-row">
                            <span class="del-legend-dot" style="background:<?= cor_e($item['colour']) ?>"></span>
                            <span class="del-legend-name"><?= cor_e($item['label']) ?></span>
                            <span class="del-legend-amt"><?= cor_money($item['amount']) ?></span>
                            <span class="del-legend-pct"><?= cor_pct($pct) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>

        <article class="cor-report-card">
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Metric</th><th>Amount</th></tr></thead>
                    <tbody>
                        <tr><td>Period</td><td><?= cor_e($filters['from']) ?> - <?= cor_e($filters['to']) ?></td></tr>
                        <tr><td>Total Orders</td><td><?= number_format((int) $vatMetrics['order_count']) ?></td></tr>
                        <tr><td>Total Invoice Sales (Incl. VAT)</td><td><?= cor_money($vatMetrics['total_sales']) ?></td></tr>
                        <tr><td>Shipping / Delivery Amount</td><td><?= cor_money($vatMetrics['shipping']) ?></td></tr>
                        <tr><td>VATable Sales (Sales - Shipping)</td><td><?= cor_money($vatMetrics['vatable_sales']) ?></td></tr>
                        <tr><td>VAT Amount (15/115 of VATable)</td><td class="cor-vat-amount"><?= cor_money($vatMetrics['vat_collected']) ?></td></tr>
                        <tr><td>Sales Excluding VAT</td><td><?= cor_money($vatMetrics['sales_ex_vat']) ?></td></tr>
                        <tr><td>Refunds Issued</td><td><?= cor_money($vatMetrics['refunds']) ?></td></tr>
                        <tr><td>VAT on Refunds</td><td><?= cor_money($vatMetrics['vat_on_refunds']) ?></td></tr>
                        <tr class="vat-net-row"><td>Net VAT Payable</td><td class="cor-profit-positive"><?= cor_money($vatMetrics['net_vat_payable']) ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>

        <article class="cor-report-card">
            <div class="card-head"><h3>Daily VAT Breakdown</h3><span>VATable sales exclude shipping</span></div>
            <div style="padding:14px 18px;">
                <?php $maxDailyVat = max(array_merge([1], array_map(static fn(array $row): float => (float) $row['vat_amount'], $vatDailyRows))); ?>
                <?php foreach ($vatDailyRows as $row): $barWidth = $maxDailyVat > 0 ? ((float) $row['vat_amount'] / $maxDailyVat) * 100 : 0; ?>
                    <div class="del-hbar-row" style="margin-bottom:6px;">
                        <div class="del-hbar-label" style="min-width:100px;font-size:11px;"><?= cor_e(date('d M', strtotime((string) $row['d']))) ?></div>
                        <div class="del-hbar-track" style="height:22px;"><div class="del-hbar-fill" style="width:<?= cor_e((string) $barWidth) ?>%;background:var(--cor-olive);"></div></div>
                        <div class="del-hbar-val" style="color:var(--cor-olive);"><?= cor_money($row['vat_amount']) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$vatDailyRows): ?><div class="cor-empty" style="padding-left:0;">No daily VAT records found.</div><?php endif; ?>
            </div>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Date</th><th>Orders</th><th>Total Sales</th><th>Shipping</th><th>VATable Sales</th><th>Ex-VAT</th><th>VAT (15%)</th></tr></thead>
                    <tbody>
                    <?php foreach ($vatDailyRows as $row): ?>
                        <tr>
                            <td><?= cor_e(date('d M Y', strtotime((string) $row['d']))) ?></td>
                            <td><?= number_format((int) $row['orders']) ?></td>
                            <td><?= cor_money($row['total_sales']) ?></td>
                            <td><?= cor_money($row['shipping']) ?></td>
                            <td><?= cor_money($row['vatable']) ?></td>
                            <td><?= cor_money($row['ex_vat']) ?></td>
                            <td class="cor-profit-positive"><?= cor_money($row['vat_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($vatDailyRows): ?>
                        <tr style="border-top:2px solid var(--cor-border);font-weight:800;"><td>Total</td><td><?= number_format((int) $vatDailyTotals['orders']) ?></td><td><?= cor_money($vatDailyTotals['total_sales']) ?></td><td><?= cor_money($vatDailyTotals['shipping']) ?></td><td><?= cor_money($vatDailyTotals['vatable']) ?></td><td><?= cor_money($vatDailyTotals['ex_vat']) ?></td><td class="cor-profit-positive"><?= cor_money($vatDailyTotals['vat_amount']) ?></td></tr>
                    <?php else: ?>
                        <tr><td colspan="7">No VAT records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'daily', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'daily', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>

        <article class="cor-report-card">
            <div class="card-head"><h3><span style="display:inline-block;width:12px;height:12px;margin-right:6px;border-radius:2px;background:var(--cor-orange-red);vertical-align:middle;"></span>VAT by Payment Method</h3><span><?= number_format(count($vatPaymentRows)) ?> methods</span></div>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Payment Method</th><th>Orders</th><th>Total (Incl. VAT)</th><th>VAT Portion</th></tr></thead>
                    <tbody>
                    <?php foreach ($vatPaymentRows as $row): ?>
                        <tr><td><?= cor_e($row['method']) ?></td><td><?= number_format((int) $row['orders']) ?></td><td><?= cor_money($row['total_incl']) ?></td><td><?= cor_money($row['vat_portion']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($vatPaymentRows): ?>
                        <tr style="border-top:2px solid var(--cor-border);font-weight:800;"><td>Total</td><td><?= number_format((int) $vatPaymentTotals['orders']) ?></td><td><?= cor_money($vatPaymentTotals['total_incl']) ?></td><td class="cor-profit-positive"><?= cor_money($vatPaymentTotals['vat_portion']) ?></td></tr>
                    <?php else: ?>
                        <tr><td colspan="4">No VAT payment records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'payment', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'payment', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>

        <article class="cor-report-card">
            <div class="card-head"><h3><span style="display:inline-block;width:12px;height:12px;margin-right:6px;border-radius:2px;background:var(--cor-amber);vertical-align:middle;"></span>Product VAT Report</h3><span><?= number_format(count($vatProductRows)) ?> products</span></div>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Product</th><th>Qty Sold</th><th>Total Sales (Incl.)</th><th>VAT Portion</th></tr></thead>
                    <tbody>
                    <?php foreach ($vatProductRows as $row): ?>
                        <tr><td><?= cor_e($row['product']) ?></td><td><?= number_format((float) $row['qty_sold'], 2) ?></td><td><?= cor_money($row['total_incl']) ?></td><td><?= cor_money($row['vat_portion']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($vatProductRows): ?>
                        <tr style="border-top:2px solid var(--cor-border);font-weight:800;"><td>Total</td><td><?= number_format((float) $vatProductTotals['qty'], 2) ?></td><td><?= cor_money($vatProductTotals['total_incl']) ?></td><td class="cor-profit-positive"><?= cor_money($vatProductTotals['vat_portion']) ?></td></tr>
                    <?php else: ?>
                        <tr><td colspan="4">No product VAT records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'products', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'products', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>
    </section>

    <section id="tab-monthly" class="cor-tab-content <?= $filters['tab'] === 'monthly' ? 'active' : '' ?>">
        <section class="cor-summary-stat-grid" aria-label="Monthly business summary">
            <article class="cor-top-card">
                <div class="tc-label">Net Sales</div>
                <div class="tc-value"><?= cor_money($monthlySummary['net_sales']) ?></div>
                <div class="tc-sub"><?= number_format((int) $monthlySummary['order_count']) ?> orders</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-amber);">
                <div class="tc-label">VAT Payable</div>
                <div class="tc-value" style="color:var(--cor-amber);"><?= cor_money($monthlySummary['vat']) ?></div>
                <div class="tc-sub">on product sales</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-olive);">
                <div class="tc-label">Gross Profit (Est.)</div>
                <div class="tc-value" style="color:var(--cor-olive);"><?= cor_money($monthlySummary['gross_profit']) ?></div>
                <div class="tc-sub"><?= number_format((int) $monthlySummary['margin_pct']) ?>% margin</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-burgundy);">
                <div class="tc-label">Stock Value</div>
                <div class="tc-value" style="color:var(--cor-burgundy);"><?= cor_money($monthlySummary['stock_cost']) ?></div>
                <div class="tc-sub">at cost price</div>
            </article>
        </section>

        <article class="cor-report-card">
            <div style="padding:18px 20px 10px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <span style="font-size:16px;" aria-hidden="true">◷</span>
                    <h3 style="margin:0;color:var(--cor-burgundy);font-size:14px;font-weight:800;">Monthly Business Summary - <?= cor_e($filters['from']) ?> to <?= cor_e($filters['to']) ?></h3>
                </div>
                <p style="margin:0 0 14px;color:var(--cor-text-light);font-size:11px;">Profit figures are estimates based on current inventory cost prices.</p>
            </div>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th style="width:55%;">Metric</th><th>Amount</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr class="mon-group-header"><td colspan="3" class="mon-group-label">Sales</td></tr>
                        <tr><td>Total Invoice Sales</td><td><?= cor_money($monthlySummary['total_sales']) ?></td><td class="mon-notes"><?= number_format((int) $monthlySummary['order_count']) ?> orders</td></tr>
                        <tr><td>Cash Sales</td><td><?= cor_money($monthlySummary['cash']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Card / Swipe Sales</td><td><?= cor_money($monthlySummary['card']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>EFT Sales</td><td><?= cor_money($monthlySummary['eft']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Other / Wallet Sales</td><td><?= cor_money($monthlySummary['wallet']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Delivery Fees</td><td><?= cor_money($monthlySummary['shipping']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Refunds Issued</td><td style="color:var(--cor-red);"><?= $monthlySummary['refunds'] > 0 ? '-' : '' ?><?= cor_money($monthlySummary['refunds']) ?></td><td class="mon-notes"></td></tr>
                        <tr style="border-top:2px solid var(--cor-border);"><td style="font-weight:800;">Net Sales</td><td style="font-weight:800;"><?= cor_money($monthlySummary['net_sales']) ?></td><td></td></tr>

                        <tr class="mon-group-header"><td colspan="3" class="mon-group-label">VAT</td></tr>
                        <tr><td>VATable Sales (excl. shipping)</td><td><?= cor_money($monthlySummary['vatable']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>VAT Amount (15%)</td><td class="cor-vat-amount"><?= cor_money($monthlySummary['vat']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Sales Excl. VAT</td><td><?= cor_money($monthlySummary['ex_vat']) ?></td><td class="mon-notes"></td></tr>

                        <tr class="mon-group-header"><td colspan="3" class="mon-group-label">Profit (Estimated)</td></tr>
                        <tr><td>Net Sales</td><td><?= cor_money($monthlySummary['net_sales']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Shipping Deducted</td><td style="color:var(--cor-red);">-<?= cor_money($monthlySummary['shipping']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Cost of Goods Sold (est.)</td><td style="color:var(--cor-red);"><?= $monthlySummary['cogs'] > 0 ? '-' : '' ?><?= cor_money($monthlySummary['cogs']) ?></td><td class="mon-notes">cost sync pending</td></tr>
                        <tr class="mon-highlight" style="border-top:2px solid var(--cor-border);"><td style="font-weight:800;">Gross Profit (est.)</td><td class="cor-profit-positive" style="font-weight:800;"><?= cor_money($monthlySummary['gross_profit']) ?></td><td class="mon-notes" style="color:var(--cor-olive) !important;"><?= number_format((int) $monthlySummary['margin_pct']) ?>% margin</td></tr>

                        <tr class="mon-group-header"><td colspan="3" class="mon-group-label">Inventory</td></tr>
                        <tr><td>Stock Cost Value</td><td><?= cor_money($monthlySummary['stock_cost']) ?></td><td class="mon-notes">cost sync pending</td></tr>
                        <tr><td>Stock Retail Value</td><td><?= cor_money($monthlySummary['stock_retail']) ?></td><td class="mon-notes">stock sync pending</td></tr>
                        <tr><td>Potential Margin</td><td class="cor-profit-positive" style="font-weight:800;"><?= cor_money($monthlySummary['potential_margin']) ?></td><td class="mon-notes"></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'monthly', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'monthly', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>
    </section>

    <section id="tab-inventory" class="cor-tab-content <?= $filters['tab'] === 'inventory' ? 'active' : '' ?>">
        <section class="inv-stat-row">
            <article class="cor-top-card">
                <div class="tc-label">SKUs Shown</div>
                <div class="tc-value" data-inv-stat="shown"><?= number_format((int) $inventoryStats['shown']) ?></div>
                <div class="tc-sub">of <span data-inv-stat="total"><?= number_format((int) $inventoryStats['total']) ?></span> total</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Total Units</div>
                <div class="tc-value" data-inv-stat="units"><?= number_format((float) $inventoryStats['units']) ?></div>
                <div class="tc-sub">in stock</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-burgundy)">
                <div class="tc-label">Cost Value</div>
                <div class="tc-value" style="color:var(--cor-burgundy)" data-inv-money="cost_value"><?= cor_money($inventoryStats['cost_value']) ?></div>
                <div class="tc-sub">total cost</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-olive)">
                <div class="tc-label">Retail Value</div>
                <div class="tc-value" style="color:var(--cor-olive)" data-inv-money="retail_value"><?= cor_money($inventoryStats['retail_value']) ?></div>
                <div class="tc-sub">at selling price</div>
            </article>
        </section>

        <section class="inv-stat-row-two">
            <article class="cor-top-card" style="border-left:4px solid var(--cor-olive)">
                <div class="tc-label">Gross Profit</div>
                <div class="tc-value" style="color:var(--cor-olive)" data-inv-money="gross_profit"><?= cor_money($inventoryStats['gross_profit']) ?></div>
                <div class="tc-sub"><span data-inv-stat="margin_pct"><?= number_format((int) $inventoryStats['margin_pct']) ?></span>% margin</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-amber)">
                <div class="tc-label">Low Stock</div>
                <div class="tc-value" style="color:var(--cor-amber)" data-inv-stat="low"><?= number_format((int) $inventoryStats['low']) ?></div>
                <div class="tc-sub">&le;5 units</div>
            </article>
        </section>

        <section class="inv-visual-grid">
            <article class="cor-report-card inv-health-card">
                <canvas id="invPieChart" width="150" height="150"></canvas>
                <div class="inv-health-legend">
                    <div class="inv-health-label">Stock Health</div>
                    <?php
                    $healthRows = [
                        ['label' => 'In Stock', 'count' => (int) $inventoryStats['in'], 'color' => '#A8CA19'],
                        ['label' => 'Low Stock (&le;5)', 'count' => (int) $inventoryStats['low'], 'color' => '#F07420'],
                        ['label' => 'Out of Stock', 'count' => (int) $inventoryStats['out'], 'color' => '#BB1B21'],
                    ];
                    foreach ($healthRows as $health):
                        $healthPct = $inventoryStats['total'] > 0 ? ((int) $health['count'] / (int) $inventoryStats['total']) * 100 : 0;
                    ?>
                        <div class="del-legend-row">
                            <span class="del-legend-dot" style="background:<?= cor_e($health['color']) ?>"></span>
                            <span class="del-legend-name"><?= $health['label'] ?></span>
                            <span class="del-legend-amt" style="color:<?= cor_e($health['color']) ?>" data-inv-health="<?= cor_e(cor_slug($health['label'])) ?>"><?= number_format((int) $health['count']) ?></span>
                            <span class="del-legend-pct" data-inv-health-pct="<?= cor_e(cor_slug($health['label'])) ?>"><?= cor_pct($healthPct) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="cor-report-card">
                <div class="card-head"><h3>Top Products by Value</h3></div>
                <div style="padding:14px 18px;">
                    <?php
                    $topMax = max(1.0, ...array_map(static fn(array $row): float => (float) ($row['retail_val'] ?? 0), $inventoryTopRows ?: [['retail_val' => 0]]));
                    $barColors = ['#A8CA19', '#3b82f6', '#F07420', '#8B44C7', '#e14b92', '#13b7bf', '#AB3619', '#20b7a7'];
                    foreach ($inventoryTopRows as $index => $row):
                        $barWidth = ((float) $row['retail_val'] / $topMax) * 100;
                        $barColor = $barColors[$index % count($barColors)];
                    ?>
                        <div class="del-hbar-row">
                            <div class="del-hbar-label"><?= cor_e(cor_short_text($row['name'], 22)) ?></div>
                            <div class="del-hbar-track" style="height:20px;"><div class="del-hbar-fill" style="width:<?= number_format($barWidth, 2, '.', '') ?>%;background:<?= cor_e($barColor) ?>"></div></div>
                            <div class="del-hbar-val"><?= cor_money($row['retail_val']) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$inventoryTopRows): ?><div class="cor-empty">No retail inventory values found.</div><?php endif; ?>
                </div>
            </article>
        </section>

        <article class="cor-report-card">
            <div class="card-head"><h3>Inventory</h3><span>WooCommerce stock, value, variations and saved cost report</span></div>
            <form class="inv-tools" method="get" data-inventory-form>
                <div data-inventory-message>
                    <?php if ($inventoryError): ?>
                        <div class="cor-data-message cor-data-error"><?= $inventoryLiveRequested ? 'Could not connect to WooCommerce: ' : '' ?><?= cor_e($inventoryError) ?></div>
                    <?php elseif ($inventoryLiveRequested && ($filters['tab'] === 'inventory' || $filters['tab'] === 'monthly')): ?>
                        <div class="cor-data-message cor-data-ok">WooCommerce inventory loaded <?= number_format((int) $inventoryStats['total']) ?> products/SKUs.</div>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="tab" value="inventory">
                <input type="hidden" name="range" value="<?= cor_e($filters['range']) ?>">
                <input type="hidden" name="from" value="<?= cor_e($filters['from']) ?>">
                <input type="hidden" name="to" value="<?= cor_e($filters['to']) ?>">
                <input type="hidden" name="status" value="<?= cor_e($filters['status']) ?>">
                <input type="hidden" name="mode" value="<?= cor_e($filters['mode']) ?>">
                <input type="hidden" name="payment" value="<?= cor_e($filters['payment']) ?>">
                <input type="hidden" name="inv_dir" value="<?= cor_e($inventoryDir) ?>">
                <input type="hidden" name="inv_live" value="1">
                <div class="inv-tools-row">
                    <input name="inv_search" value="<?= cor_e($inventorySearch) ?>" placeholder="Search product or SKU...">
                    <select name="inv_category" data-portal-custom-select>
                        <option value="all">All</option>
                        <?php foreach ($inventoryCategories as $category): ?>
                            <option value="<?= cor_e($category) ?>" <?= $inventoryCategory === $category ? 'selected' : '' ?>><?= cor_e($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="inv_sort" data-portal-custom-select>
                        <option value="name" <?= $inventorySort === 'name' ? 'selected' : '' ?>>Sort: Name</option>
                        <option value="qty" <?= $inventorySort === 'qty' ? 'selected' : '' ?>>Sort: Qty</option>
                        <option value="retail_val" <?= $inventorySort === 'retail_val' ? 'selected' : '' ?>>Sort: Retail Value</option>
                        <option value="price" <?= $inventorySort === 'price' ? 'selected' : '' ?>>Sort: Price</option>
                        <option value="cost_val" <?= $inventorySort === 'cost_val' ? 'selected' : '' ?>>Sort: Cost Value</option>
                    </select>
                    <a class="inv-sort-btn" href="?<?= cor_e(http_build_query(array_merge($inventoryQueryBase, ['inv_dir' => $inventoryDir === 'asc' ? 'desc' : 'asc']))) ?>"><?= $inventoryDir === 'asc' ? '&uarr; Asc' : '&darr; Desc' ?></a>
                    <select name="inv_stock" data-portal-custom-select>
                        <option value="all" <?= $inventoryStock === 'all' ? 'selected' : '' ?>>All Stock</option>
                        <option value="in" <?= $inventoryStock === 'in' ? 'selected' : '' ?>>In Stock</option>
                        <option value="low" <?= $inventoryStock === 'low' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="out" <?= $inventoryStock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                    </select>
                    <button class="inv-refresh-btn" type="submit">Refresh</button>
                </div>
                <div class="inv-count" data-inventory-count>Showing <?= number_format((int) $inventoryStats['shown']) ?> of <?= number_format((int) $inventoryStats['total']) ?> SKUs</div>
            </form>
            <div class="cor-summary-actions" style="padding:10px 16px;border-bottom:1px solid var(--cor-border);margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($inventoryQueryBase, ['export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($inventoryQueryBase, ['export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
            <div class="inv-table-wrap">
                <table class="cor-products-table" style="width:100%;border-collapse:collapse;">
                    <thead><tr><th>Product / Category</th><th>Variant</th><th>SKU</th><th>Stock Status</th><th>QTY</th><th>Regular Price</th><th>Sale Price</th><th>Cost</th><th>Cost Value</th><th>Retail Value</th><th>Profit</th></tr></thead>
                    <tbody data-inventory-body>
                    <?php foreach ($inventoryFilteredRows as $row):
                        $rowClass = $row['stock_class'] === 'out' ? 'inv-row-out' : ($row['stock_class'] === 'low' ? 'inv-row-low' : '');
                        $qtyClass = 'inv-qty-' . $row['stock_class'];
                        $stockLabel = $row['stock_class'] === 'out' ? 'Out of Stock' : ($row['stock_class'] === 'low' ? 'Low Stock' : 'In Stock');
                        $stockBadge = $row['stock_class'] === 'out' ? 'inv-outstock' : ($row['stock_class'] === 'low' ? 'inv-lowstock' : 'inv-instock');
                    ?>
                        <tr class="<?= cor_e($rowClass) ?>" data-inv-row>
                            <td>
                                <div style="font-weight:700;"><?= cor_e($row['name']) ?></div>
                                <?php if ($row['category'] !== ''): ?><div style="margin-top:1px;color:var(--cor-text-light);font-size:10px;"><?= cor_e($row['category']) ?></div><?php endif; ?>
                            </td>
                            <td><?= $row['variant'] !== '' ? cor_e($row['variant']) : '-' ?></td>
                            <td><?= $row['sku'] !== '-' ? '<span class="inv-sku-chip">' . cor_e($row['sku']) . '</span>' : '<span style="color:var(--cor-text-light)">-</span>' ?></td>
                            <td><span class="<?= cor_e($stockBadge) ?>"><?= cor_e($stockLabel) ?></span></td>
                            <td class="<?= cor_e($qtyClass) ?>"><?= number_format((float) $row['qty']) ?></td>
                            <td><?= cor_money($row['regular_price'] ?? $row['price']) ?></td>
                            <td><?= ((float) ($row['sale_price'] ?? 0) > 0) ? cor_money($row['sale_price']) : '<span style="color:var(--cor-text-light)">-</span>' ?></td>
                            <td><input class="inv-cost-input" type="number" min="0" step="0.01" value="<?= cor_e(number_format((float) $row['cost'], 2, '.', '')) ?>" data-inv-cost data-id="<?= (int) ($row['id'] ?? 0) ?>" data-qty="<?= cor_e((string) (float) $row['qty']) ?>" data-retail="<?= cor_e((string) (float) $row['retail_val']) ?>" aria-label="Cost for <?= cor_e($row['name']) ?>"></td>
                            <td data-inv-row-cost-value><?= cor_money($row['cost_val']) ?></td>
                            <td data-inv-row-retail-value><?= cor_money($row['retail_val']) ?></td>
                            <td data-inv-row-profit class="<?= ((float) $row['profit'] < 0) ? 'cor-profit-negative' : 'cor-profit-positive' ?>"><?= cor_money($row['profit']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$inventoryFilteredRows): ?>
                        <tr><td colspan="11" style="height:auto;padding:24px !important;text-align:center;color:var(--cor-text-light) !important;"><?= $inventoryError ? ($inventoryLiveRequested ? 'Could not connect to WooCommerce: ' : '') . cor_e($inventoryError) : 'No products match this inventory view.' ?></td></tr>
                    <?php else: ?>
                        <tr style="border-top:3px solid var(--cor-border);background:var(--cor-cream);">
                            <td colspan="4" style="font-weight:800;">TOTAL</td>
                            <td style="font-weight:800;" data-inv-total="qty"><?= number_format((float) $inventoryStats['shown_qty']) ?></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td style="font-weight:800;" data-inv-total="cost"><?= cor_money($inventoryStats['shown_cost']) ?></td>
                            <td style="font-weight:800;" data-inv-total="retail"><?= cor_money($inventoryStats['shown_retail']) ?></td>
                            <td class="cor-profit-positive" style="font-weight:800;" data-inv-total="profit"><?= cor_money($inventoryStats['shown_profit']) ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section id="tab-orders" class="cor-tab-content <?= $filters['tab'] === 'orders' ? 'active' : '' ?>">
        <article class="cor-report-card">
            <div class="card-head"><h3>Full Orders Table</h3><span>50 rows per page</span></div>
            <div class="cor-orders-wrap">
                <table class="cor-orders-table">
                    <thead><tr><th>Order ID</th><th>Date</th><th>Customer</th><th>Mobile</th><th>Mode</th><th>Payment</th><th>Amount</th><th>Status</th><th>Packed By</th></tr></thead>
                    <tbody>
                    <?php foreach ($orderRows as $row): ?>
                        <tr>
                            <td><?= cor_e($row['order_number']) ?></td>
                            <td><?= cor_e(date('d M H:i', strtotime((string) $row['created_at']))) ?></td>
                            <td><?= cor_e($row['customer_name']) ?></td>
                            <td><?= cor_e($row['customer_contact']) ?></td>
                            <td><span class="cor-pill mode-<?= cor_e(cor_slug($row['order_type'])) ?>"><?= cor_e(ucwords((string) $row['order_type'])) ?></span></td>
                            <td><span class="cor-pill pay-<?= cor_e(cor_slug($row['payment_method'] ?: 'other')) ?>"><?= cor_e($row['payment_method'] ?: 'Other') ?></span></td>
                            <td><?= cor_money($row['total_amount']) ?></td>
                            <td><span class="cor-pill cor-status-<?= cor_e(cor_slug($row['status'])) ?>"><?= cor_e(cor_order_status_label((string) $row['status'])) ?></span></td>
                            <td><?= cor_e($row['packer_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$orderRows): ?><tr><td colspan="9">No matching orders found for selected filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="cor-pagination">
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a class="cor-page-btn <?= $i === $page ? 'active' : '' ?>" href="?<?= cor_e(http_build_query($queryBase + ['tab' => 'orders', 'p' => $i])) ?>"><?= number_format($i) ?></a>
                <?php endfor; ?>
            </div>
        </article>
    </section>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('[data-cor-filter]');
  var rangeInput = document.querySelector('[data-cor-range]');
  var tabInput = document.querySelector('[data-cor-tab-input]');
  var customDates = document.querySelector('[data-custom-dates]');
  var loadingBanner = document.querySelector('[data-cor-loading]');
  var filterPanel = document.querySelector('[data-orders-filter-panel]');

  function setOrdersFilterOpen(open) {
    if (!filterPanel) return;
    var toggle = filterPanel.querySelector('[data-orders-filter-toggle]');
    var body = filterPanel.querySelector('[data-orders-filter-body]');
    var state = filterPanel.querySelector('[data-orders-filter-state]');
    filterPanel.classList.toggle('is-collapsed', !open);
    if (toggle) toggle.setAttribute('aria-expanded', String(open));
    if (body) body.hidden = !open;
    if (state) state.textContent = open ? 'Expanded' : 'Collapsed';
    localStorage.setItem('ordersFiltersOpen', open ? '1' : '0');
  }

  if (filterPanel) {
    setOrdersFilterOpen(localStorage.getItem('ordersFiltersOpen') === '1');
    filterPanel.querySelector('[data-orders-filter-toggle]')?.addEventListener('click', function () {
      setOrdersFilterOpen(filterPanel.classList.contains('is-collapsed'));
    });
  }

  function showLoading() {
    if (loadingBanner) loadingBanner.hidden = false;
  }

  if (form) {
    form.addEventListener('submit', showLoading);
  }

  var rangeSelect = document.querySelector('[data-orders-range]');
  rangeSelect?.addEventListener('change', function () {
    if (!rangeInput) return;
    rangeInput.value = rangeSelect.value || 'month';
    if (customDates) customDates.style.display = rangeInput.value === 'custom' ? '' : 'none';
  });

  document.querySelectorAll('.cor-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var nextTab = tab.dataset.tab || 'sales';
      if (tabInput) tabInput.value = nextTab;
      var url = new URL(window.location.href);
      url.searchParams.set('tab', nextTab);
      url.searchParams.delete('p');
      showLoading();
      window.location.href = url.toString();
    });
  });

  var inventoryForm = document.querySelector('[data-inventory-form]');
  var inventoryBody = document.querySelector('[data-inventory-body]');
  var inventoryMessage = document.querySelector('[data-inventory-message]');
  var inventoryCount = document.querySelector('[data-inventory-count]');

  function invNumber(value) {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(value || 0));
  }

  function invMoney(value) {
    return 'N$' + new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0));
  }

  function invEscape(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
  }

  function invMessage(type, text) {
    if (!inventoryMessage) return;
    var cls = type === 'error' ? 'cor-data-error' : (type === 'loading' ? 'cor-data-loading' : 'cor-data-ok');
    inventoryMessage.innerHTML = '<div class="cor-data-message ' + cls + '">' + invEscape(text) + '</div>';
  }

  function invSetText(selector, text) {
    var el = document.querySelector(selector);
    if (el) el.textContent = text;
  }

  function invRenderLoading() {
    if (inventoryBody) {
      inventoryBody.innerHTML = '<tr><td colspan="11" style="height:auto;padding:24px !important;text-align:center;color:var(--cor-text-light) !important;">Loading WooCommerce products...</td></tr>';
    }
  }

  function invRenderStats(stats) {
    stats = stats || {};
    invSetText('[data-inv-stat="shown"]', invNumber(stats.shown));
    invSetText('[data-inv-stat="total"]', invNumber(stats.total));
    invSetText('[data-inv-stat="units"]', invNumber(stats.units));
    invSetText('[data-inv-stat="margin_pct"]', invNumber(stats.margin_pct));
    invSetText('[data-inv-stat="low"]', invNumber(stats.low));
    invSetText('[data-inv-money="cost_value"]', invMoney(stats.cost_value));
    invSetText('[data-inv-money="retail_value"]', invMoney(stats.retail_value));
    invSetText('[data-inv-money="gross_profit"]', invMoney(stats.gross_profit));
    var total = Number(stats.total || 0);
    [
      ['in-stock', stats.in],
      ['low-stock-le-5', stats.low],
      ['out-of-stock', stats.out]
    ].forEach(function (item) {
      var key = item[0];
      var count = Number(item[1] || 0);
      invSetText('[data-inv-health="' + key + '"]', invNumber(count));
      invSetText('[data-inv-health-pct="' + key + '"]', total > 0 ? ((count / total) * 100).toFixed(1) + '%' : '0.0%');
    });
    if (inventoryCount) {
      inventoryCount.textContent = 'Showing ' + invNumber(stats.shown) + ' of ' + invNumber(stats.total) + ' SKUs';
    }
  }

  function invStockLabel(cls) {
    if (cls === 'out') return 'Out of Stock';
    if (cls === 'low') return 'Low Stock';
    return 'In Stock';
  }

  function invUpdateProfitCell(cell, value) {
    if (!cell) return;
    cell.textContent = invMoney(value);
    cell.classList.toggle('cor-profit-negative', Number(value || 0) < 0);
    cell.classList.toggle('cor-profit-positive', Number(value || 0) >= 0);
  }

  function invRecalculateVisibleTotals() {
    if (!inventoryBody) return;
    var rows = Array.prototype.slice.call(inventoryBody.querySelectorAll('tr[data-inv-row]'));
    var totals = rows.reduce(function (carry, row) {
      var input = row.querySelector('[data-inv-cost]');
      var qty = Number(input && input.dataset.qty || 0);
      var retail = Number(input && input.dataset.retail || 0);
      var cost = Number(input && input.value || 0);
      var costValue = cost * qty;
      var profit = retail - costValue;
      var costCell = row.querySelector('[data-inv-row-cost-value]');
      var profitCell = row.querySelector('[data-inv-row-profit]');
      if (costCell) costCell.textContent = invMoney(costValue);
      invUpdateProfitCell(profitCell, profit);
      carry.qty += qty;
      carry.cost += costValue;
      carry.retail += retail;
      carry.profit += profit;
      return carry;
    }, { qty: 0, cost: 0, retail: 0, profit: 0 });

    invSetText('[data-inv-money="cost_value"]', invMoney(totals.cost));
    invSetText('[data-inv-money="gross_profit"]', invMoney(totals.profit));
    invSetText('[data-inv-stat="margin_pct"]', totals.retail > 0 ? invNumber(Math.round((totals.profit / totals.retail) * 100)) : '0');
    invSetText('[data-inv-total="qty"]', invNumber(totals.qty));
    invSetText('[data-inv-total="cost"]', invMoney(totals.cost));
    invSetText('[data-inv-total="retail"]', invMoney(totals.retail));
    invSetText('[data-inv-total="profit"]', invMoney(totals.profit));
  }

  function invRenderRows(rows, stats, error) {
    if (!inventoryBody) return;
    rows = Array.isArray(rows) ? rows : [];
    if (!rows.length) {
      inventoryBody.innerHTML = '<tr><td colspan="11" style="height:auto;padding:24px !important;text-align:center;color:var(--cor-text-light) !important;">' + invEscape(error || 'No WooCommerce products returned.') + '</td></tr>';
      return;
    }
    var html = rows.map(function (row) {
      var stockClass = row.stock_class === 'out' ? 'out' : (row.stock_class === 'low' ? 'low' : 'in');
      var trClass = stockClass === 'out' ? 'inv-row-out' : (stockClass === 'low' ? 'inv-row-low' : '');
      var qtyClass = 'inv-qty-' + stockClass;
      var badgeClass = stockClass === 'out' ? 'inv-outstock' : (stockClass === 'low' ? 'inv-lowstock' : 'inv-instock');
      var sale = Number(row.sale_price || 0) > 0 ? invMoney(row.sale_price) : '<span style="color:var(--cor-text-light)">-</span>';
      var sku = row.sku && row.sku !== '-' ? '<span class="inv-sku-chip">' + invEscape(row.sku) + '</span>' : '<span style="color:var(--cor-text-light)">-</span>';
      return '<tr class="' + trClass + '" data-inv-row>' +
        '<td><div style="font-weight:700;">' + invEscape(row.name) + '</div>' + (row.category ? '<div style="margin-top:1px;color:var(--cor-text-light);font-size:10px;">' + invEscape(row.category) + '</div>' : '') + '</td>' +
        '<td>' + (row.variant ? invEscape(row.variant) : '-') + '</td>' +
        '<td>' + sku + '</td>' +
        '<td><span class="' + badgeClass + '">' + invStockLabel(stockClass) + '</span></td>' +
        '<td class="' + qtyClass + '">' + invNumber(row.qty) + '</td>' +
        '<td>' + invMoney(row.regular_price) + '</td>' +
        '<td>' + sale + '</td>' +
        '<td><input class="inv-cost-input" type="number" min="0" step="0.01" value="' + Number(row.cost || 0).toFixed(2) + '" data-inv-cost data-id="' + invEscape(row.id || 0) + '" data-qty="' + invEscape(row.qty || 0) + '" data-retail="' + invEscape(row.retail_val || 0) + '" aria-label="Cost for ' + invEscape(row.name) + '"></td>' +
        '<td data-inv-row-cost-value>' + invMoney(row.cost_val) + '</td>' +
        '<td data-inv-row-retail-value>' + invMoney(row.retail_val) + '</td>' +
        '<td data-inv-row-profit class="' + (Number(row.profit || 0) < 0 ? 'cor-profit-negative' : 'cor-profit-positive') + '">' + invMoney(row.profit) + '</td>' +
      '</tr>';
    }).join('');
    html += '<tr style="border-top:3px solid var(--cor-border);background:var(--cor-cream);">' +
      '<td colspan="4" style="font-weight:800;">TOTAL</td>' +
      '<td style="font-weight:800;" data-inv-total="qty">' + invNumber(stats && stats.shown_qty) + '</td>' +
      '<td></td><td></td><td></td>' +
      '<td style="font-weight:800;" data-inv-total="cost">' + invMoney(stats && stats.shown_cost) + '</td>' +
      '<td style="font-weight:800;" data-inv-total="retail">' + invMoney(stats && stats.shown_retail) + '</td>' +
      '<td class="cor-profit-positive" style="font-weight:800;" data-inv-total="profit">' + invMoney(stats && stats.shown_profit) + '</td>' +
    '</tr>';
    inventoryBody.innerHTML = html;
    invRecalculateVisibleTotals();
  }

  if (inventoryForm) {
    inventoryForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      var button = inventoryForm.querySelector('.inv-refresh-btn');
      var original = button ? button.textContent : '';
      if (button) {
        button.disabled = true;
        button.textContent = 'Loading...';
      }
      invMessage('loading', 'Loading WooCommerce products...');
      invRenderLoading();
      try {
        var url = new URL(window.location.href);
        new FormData(inventoryForm).forEach(function (value, key) {
          url.searchParams.set(key, value);
        });
        url.searchParams.set('tab', 'inventory');
        url.searchParams.set('inv_live', '1');
        url.searchParams.set('inventory_ajax', '1');
        var response = await fetch(url.toString(), {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        var data = await response.json().catch(function () { return null; });
        if (!response.ok || !data) {
          throw new Error('Inventory request failed with HTTP ' + response.status);
        }
        invRenderStats(data.stats || {});
        invRenderRows(data.rows || [], data.stats || {}, data.error || null);
        if (data.ok) {
          invMessage('ok', 'WooCommerce inventory loaded ' + invNumber((data.stats || {}).total) + ' products/SKUs.');
        } else {
          invMessage('error', 'Could not connect to WooCommerce: ' + (data.error || 'Unknown error'));
        }
      } catch (error) {
        invMessage('error', 'Could not connect to WooCommerce: ' + error.message);
        invRenderRows([], {}, 'Could not connect to WooCommerce: ' + error.message);
      } finally {
        if (button) {
          button.disabled = false;
          button.textContent = original || 'Refresh';
        }
      }
    });
  }

  if (inventoryBody) {
    async function invSaveCostInput(input) {
      if (!input || input.dataset.saving === '1') return;
      var productId = Number(input.dataset.id || 0);
      var cost = Number(input.value || 0);
      var savedCost = input.dataset.savedCost || input.defaultValue || '';
      if (Number(savedCost || 0).toFixed(2) === cost.toFixed(2)) return;
      if (!productId) {
        input.classList.add('is-error');
        invMessage('error', 'Could not save cost: missing product or variation id.');
        return;
      }
      if (cost < 0 || !isFinite(cost)) {
        input.classList.add('is-error');
        invMessage('error', 'Could not save cost: enter a valid non-negative number.');
        return;
      }

      input.dataset.saving = '1';
      input.classList.remove('is-saved', 'is-error');
      input.classList.add('is-saving');
      invMessage('loading', 'Saving inventory cost...');
      try {
        var formData = new FormData();
        formData.set('inventory_cost_ajax', '1');
        formData.set('product_id', String(productId));
        formData.set('cost', cost.toFixed(2));
        var response = await fetch(window.location.pathname, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: formData
        });
        var data = await response.json().catch(function () { return null; });
        if (!response.ok || !data || !data.ok) {
          throw new Error((data && data.error) || ('Cost save failed with HTTP ' + response.status));
        }
        input.value = Number(data.cost || cost).toFixed(2);
        input.defaultValue = input.value;
        input.dataset.savedCost = input.value;
        input.classList.remove('is-saving');
        input.classList.add('is-saved');
        invRecalculateVisibleTotals();
        invMessage('ok', 'Saved inventory cost.');
      } catch (error) {
        input.classList.remove('is-saving');
        input.classList.add('is-error');
        invMessage('error', 'Could not save inventory cost: ' + error.message);
      } finally {
        delete input.dataset.saving;
      }
    }

    inventoryBody.addEventListener('input', function (event) {
      if (event.target && event.target.matches('[data-inv-cost]')) {
        event.target.classList.remove('is-saved', 'is-error');
        invRecalculateVisibleTotals();
        clearTimeout(event.target._invCostTimer);
        event.target._invCostTimer = setTimeout(function () {
          invSaveCostInput(event.target);
        }, 900);
      }
    });

    inventoryBody.addEventListener('change', function (event) {
      if (event.target && event.target.matches('[data-inv-cost]')) {
        invSaveCostInput(event.target);
      }
    });

    inventoryBody.addEventListener('focusout', function (event) {
      if (event.target && event.target.matches('[data-inv-cost]')) {
        invSaveCostInput(event.target);
      }
    });

    invRecalculateVisibleTotals();
  }

  var daily14El = document.getElementById('daily14Chart');
  if (daily14El && window.Chart) {
    new Chart(daily14El.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode(array_column($daily14Rows, 'd'), JSON_UNESCAPED_SLASHES) ?>,
        datasets: [{
          label: 'Revenue (N$)',
          data: <?= json_encode(array_map(static fn(array $row): float => round((float) $row['rev'], 2), $daily14Rows), JSON_UNESCAPED_SLASHES) ?>,
          borderColor: '#AB3619',
          backgroundColor: 'rgba(171,54,25,0.08)',
          borderWidth: 2,
          pointRadius: 3,
          fill: true,
          tension: 0.3
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { ticks: { font: { family: 'Inter', size: 10 }, color: '#6B4C3B' }, grid: { display: false } },
          y: { ticks: { font: { family: 'Inter', size: 10 }, color: '#6B4C3B' }, grid: { color: '#EDE3D8' } }
        }
      }
    });
  }

  var delPieEl = document.getElementById('delPieChart');
  var delPieLabels = <?= json_encode(array_map(static fn(array $row): string => ucwords((string) $row['label']), $deliveryRows), JSON_UNESCAPED_SLASHES) ?>;
  var delPieData = <?= json_encode(array_map(static fn(array $row): float => round((float) $row['total_cost'], 2), $deliveryRows), JSON_UNESCAPED_SLASHES) ?>;
  var delPieColors = <?= json_encode(array_map(static fn(int $index): string => ['#A8CA19', '#AB3619', '#F07420', '#BB1B21', '#721B1A', '#6B4C3B', '#EDE3D8'][$index % 7], array_keys($deliveryRows)), JSON_UNESCAPED_SLASHES) ?>;
  if (delPieEl && window.Chart && delPieData.length) {
    new Chart(delPieEl.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: delPieLabels,
        datasets: [{ data: delPieData, backgroundColor: delPieColors, borderWidth: 2, borderColor: '#FDF6EE' }]
      },
      options: {
        responsive: false,
        plugins: { legend: { display: false } },
        cutout: '55%'
      }
    });
  }

  var delDayEl = document.getElementById('delDayChart');
  if (delDayEl && window.Chart) {
    new Chart(delDayEl.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode(array_column($deliveryDayRows, 'd'), JSON_UNESCAPED_SLASHES) ?>,
        datasets: [{
          label: 'Orders',
          data: <?= json_encode(array_map(static fn(array $row): int => (int) $row['orders'], $deliveryDayRows), JSON_UNESCAPED_SLASHES) ?>,
          borderColor: '#AB3619',
          backgroundColor: 'rgba(171,54,25,0.08)',
          borderWidth: 2,
          pointRadius: 3,
          fill: true,
          tension: 0.3
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { ticks: { font: { family: 'Inter', size: 10 }, color: '#6B4C3B' }, grid: { display: false } },
          y: { ticks: { font: { family: 'Inter', size: 10 }, color: '#6B4C3B', precision: 0 }, grid: { color: '#EDE3D8' } }
        }
      }
    });
  }

  var vatPieEl = document.getElementById('vatPieChart');
  var vatPieData = <?= json_encode([
      round((float) $vatMetrics['sales_ex_vat'], 2),
      round((float) $vatMetrics['vat_collected'], 2),
      round((float) $vatMetrics['shipping'], 2),
  ], JSON_UNESCAPED_SLASHES) ?>;
  if (vatPieEl && window.Chart && vatPieData.some(function (value) { return value > 0; })) {
    new Chart(vatPieEl.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: ['Ex-VAT Revenue', 'VAT Collected', 'Shipping'],
        datasets: [{
          data: vatPieData,
          backgroundColor: ['#AB3619', '#A8CA19', '#F07420'],
          borderWidth: 2,
          borderColor: '#FDF6EE'
        }]
      },
      options: {
        responsive: false,
        plugins: { legend: { display: false } },
        cutout: '55%'
      }
    });
  }

  var invPieEl = document.getElementById('invPieChart');
  var invPieData = <?= json_encode([
      (int) $inventoryStats['in'],
      (int) $inventoryStats['low'],
      (int) $inventoryStats['out'],
  ], JSON_UNESCAPED_SLASHES) ?>;
  if (invPieEl && window.Chart && invPieData.some(function (value) { return value > 0; })) {
    new Chart(invPieEl.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: ['In Stock', 'Low Stock', 'Out of Stock'],
        datasets: [{
          data: invPieData,
          backgroundColor: ['#A8CA19', '#F07420', '#BB1B21'],
          borderWidth: 2,
          borderColor: '#FDF6EE'
        }]
      },
      options: {
        responsive: false,
        plugins: { legend: { display: false } },
        cutout: '50%'
      }
    });
  }
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
