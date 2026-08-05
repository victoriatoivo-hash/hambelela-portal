<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/notifications.php';
require_once BASE_PATH . '/shared/employee-features.php';
require_once BASE_PATH . '/shared/packing-notifications.php';
require_once BASE_PATH . '/shared/epi/bootstrap.php';

const OPS_ORDER_STATUSES = [
    'new_order' => 'New Order',
    'assigned' => 'Assigned',
    'in_progress' => 'In Progress',
    'packed' => 'Packed',
    'verified' => 'Verified',
    'ready_for_collection' => 'Ready for Collection',
    'ready_for_courier' => 'Ready for Courier',
    'ready_for_delivery' => 'Ready for Delivery',
    'completed' => 'Completed',
    'error_logged' => 'Error Logged',
    'correction_required' => 'Correction Required',
];

const OPS_ERROR_CATEGORIES = [
    'wrong_product_packed' => 'Wrong product packed',
    'wrong_quantity_packed' => 'Wrong quantity packed',
    'incorrect_pouch_used' => 'Incorrect pouch used',
    'product_not_labelled_correctly' => 'Product not labelled correctly',
    'stock_not_updated' => 'Stock not updated',
    'dirty_workstation' => 'Dirty workstation',
    'checklist_not_completed' => 'Checklist not completed',
    'order_delayed' => 'Order delayed',
    'courier_issue' => 'Courier issue',
    'customer_complaint' => 'Customer complaint',
    'petty_cash_discrepancy' => 'Petty cash discrepancy',
    'incorrect_formulation' => 'Incorrect formulation',
    'damaged_stock' => 'Damaged stock',
    'poor_communication' => 'Poor communication',
];

const OPS_BUSINESS_START = '08:00:00';
const OPS_BUSINESS_END = '17:00:00';

/** Payment methods offered by the website/POS, in their display order. */
function ops_website_payment_methods(): array
{
    return [
        'Cash',
        'Card/Swipe',
        'EFT',
        'FNB eWallet',
        'EasyWallet',
        'Blue Wallet',
        'Nedbank',
        'NetBank Wallet',
        'Pay2Cell',
        'PayToday',
        'DPO',
    ];
}

function ops_payment_method_map(): array
{
    return [
        'cash' => 'Cash', 'card_swipe' => 'Swipe', 'eft' => 'EFT',
        'fnb_ewallet' => 'FNB eWallet', 'easywallet' => 'EasyWallet',
        'blue_wallet' => 'Blue Wallet', 'nedbank' => 'Nedbank',
        'netbank_wallet' => 'NetBank Wallet', 'pay2cell' => 'Pay2Cell',
        'paytoday' => 'PayToday', 'dpo' => 'DPO',
    ];
}

function ops_normalize_payment_code(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');
    $aliases = [
        'cash' => 'cash', 'cod' => 'cash', 'cash_on_delivery' => 'cash', 'cash_payment' => 'cash',
        'card' => 'card_swipe', 'card_payment' => 'card_swipe', 'swipe' => 'card_swipe', 'card_swipe' => 'card_swipe',
        'eft' => 'eft', 'bacs' => 'eft', 'bank_transfer' => 'eft', 'direct_bank_transfer' => 'eft',
        'fnb_ewallet' => 'fnb_ewallet', 'fnb_e_wallet' => 'fnb_ewallet', 'fnbwlt' => 'fnb_ewallet',
        'easywallet' => 'easywallet', 'easy_wallet' => 'easywallet', 'easywlt' => 'easywallet',
        'bluewallet' => 'blue_wallet', 'blue_wallet' => 'blue_wallet', 'bluewlt' => 'blue_wallet',
        'nedbank' => 'nedbank', 'netbank_wallet' => 'netbank_wallet',
        'pay2cell' => 'pay2cell', 'pay_2_cell' => 'pay2cell', 'paytoday' => 'paytoday', 'pay_today' => 'paytoday',
        'dpo' => 'dpo', 'dpo_pay' => 'dpo', 'dpo_paygate' => 'dpo', 'woocommerce_dpo' => 'dpo', 'paygate' => 'dpo', 'paygate_payweb3' => 'dpo',
    ];
    if (isset($aliases[$value])) return $aliases[$value];
    foreach (ops_payment_method_map() as $code => $label) {
        if (strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $label)) === $value) return $code;
    }
    return '';
}

function ops_payment_label(string $code): string
{
    return ops_payment_method_map()[$code] ?? ucwords(str_replace('_', ' ', $code));
}

function ops_normalize_website_payment_method(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[\s_-]+/', ' ', $value) ?? '';

    $patterns = [
        'FNB eWallet' => '/\bfnb\s*e\s*wallet\b/i',
        'NetBank Wallet' => '/\bnet\s*bank\s*wallet\b/i',
        'EasyWallet' => '/\beasy\s*wallet\b/i',
        'Blue Wallet' => '/\bblue\s*wallet\b|\bbluewallet\b/i',
        'Pay2Cell' => '/\bpay\s*2\s*cell\b/i',
        'PayToday' => '/\bpay\s*today\b/i',
        'Card/Swipe' => '/\bcard\s*\/\s*swipe\b|\bcard\b|\bswipe\b/i',
        'EFT' => '/\beft\b|\bbank transfer\b|\bbacs\b/i',
        'Nedbank' => '/\bnedbank\b/i',
        'Cash' => '/\bcash\b/i',
        'DPO' => '/\bdpo\b|\bpaygate\b/i',
    ];

    foreach ($patterns as $method => $pattern) {
        if (preg_match($pattern, $value)) {
            return $method;
        }
    }

    return '';
}

function ops_collect_order_payment_text($value, string $key = '', array &$entries = []): void
{
    if (is_array($value)) {
        foreach ($value as $childKey => $childValue) {
            ops_collect_order_payment_text($childValue, (string) $childKey, $entries);
        }
        return;
    }

    if (!is_scalar($value)) {
        return;
    }

    $text = is_bool($value) ? ($value ? 'true' : 'false') : trim((string) $value);
    if ($text === '') {
        return;
    }

    if (($text[0] ?? '') === '{' || ($text[0] ?? '') === '[') {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            ops_collect_order_payment_text($decoded, $key, $entries);
        }
    }

    $entries[] = trim($key . ' ' . $text);
}

/**
 * Resolve the real WooCommerce/POS tender. Generic split-payment titles are
 * never stored as a method: exact component methods are returned, otherwise
 * an empty value lets the front person choose without sync overwriting it.
 */
function ops_wc_payment_method(array $order): string
{
    $allocations = ops_wc_payment_allocations($order);
    if ($allocations) {
        return implode(' / ', array_map(static fn(array $allocation): string => ops_payment_label((string) $allocation['method']), $allocations));
    }
    $title = trim((string) ($order['payment_method_title'] ?? ''));
    $methodId = trim((string) ($order['payment_method'] ?? ''));
    $metadata = [];
    ops_collect_order_payment_text($order['meta_data'] ?? [], '', $metadata);
    $metadataText = implode(' | ', $metadata);
    $allText = trim($title . ' | ' . $methodId . ' | ' . $metadataText);
    $split = (bool) preg_match('/\bsplit[\s_-]*(payment|tender)?\b/i', $allText);

    $components = [];
    foreach (ops_website_payment_methods() as $candidate) {
        $candidatePattern = [
            'Cash' => '/\bcash\b/i', 'Card/Swipe' => '/\bcard\b|\bswipe\b/i',
            'EFT' => '/\beft\b|\bbank transfer\b|\bbacs\b/i',
            'FNB eWallet' => '/\bfnb\s*e\s*wallet\b/i', 'EasyWallet' => '/\beasy\s*wallet\b/i',
            'Blue Wallet' => '/\bblue\s*wallet\b|\bbluewallet\b/i', 'Nedbank' => '/\bnedbank\b/i',
            'NetBank Wallet' => '/\bnet\s*bank\s*wallet\b/i', 'Pay2Cell' => '/\bpay\s*2\s*cell\b/i',
            'PayToday' => '/\bpay\s*today\b/i',
            'DPO' => '/\bdpo\b|\bpaygate\b/i',
        ][$candidate];
        if (preg_match($candidatePattern, $metadataText)) {
            $components[] = $candidate;
        }
    }
    $components = array_values(array_unique($components));

    if ($split || count($components) > 1) {
        return count($components) > 1 ? implode(' & ', $components) : '';
    }

    return ops_normalize_website_payment_method($title)
        ?: ops_normalize_website_payment_method($methodId);
}

function ops_ensure_order_payment_schema(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!ops_database_ready() || !ops_table_exists('ops_orders')) return false;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS order_payment_allocations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            payment_method VARCHAR(30) NOT NULL,
            amount_cents BIGINT NOT NULL,
            transaction_reference VARCHAR(190) NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'portal',
            source_version VARCHAR(80) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by_employee_id INT NULL,
            UNIQUE KEY uq_order_payment_method (order_id, payment_method),
            KEY idx_order_payment_updated (order_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS order_payment_allocation_audit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            previous_allocations_json LONGTEXT NULL,
            new_allocations_json LONGTEXT NOT NULL,
            changed_by_employee_id INT NULL,
            source VARCHAR(30) NOT NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_payment_audit_order (order_id, changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $orderColumns = [
            'payment_source' => "VARCHAR(30) NOT NULL DEFAULT 'woocommerce'",
            'payment_updated_at' => 'DATETIME NULL',
            'payment_updated_by_employee_id' => 'INT NULL',
            'payment_version' => 'VARCHAR(80) NULL',
        ];
        foreach ($orderColumns as $column => $definition) {
            if (!ops_column_exists('ops_orders', $column)) db()->exec("ALTER TABLE ops_orders ADD COLUMN {$column} {$definition}");
        }
        $legacyRows = ops_rows(
            "SELECT o.id, o.payment_method, o.total_amount, o.updated_at
             FROM ops_orders o
             LEFT JOIN order_payment_allocations p ON p.order_id = o.id
             WHERE p.id IS NULL AND o.payment_status = 'paid' AND COALESCE(o.payment_method, '') <> ''
             LIMIT 500"
        );
        $legacyInsert = db()->prepare('INSERT IGNORE INTO order_payment_allocations (order_id, payment_method, amount_cents, source, source_version) VALUES (?, ?, ?, ?, ?)');
        foreach ($legacyRows as $legacyRow) {
            $method = ops_normalize_payment_code((string) $legacyRow['payment_method']);
            if ($method === '') continue;
            $legacyInsert->execute([(int) $legacyRow['id'], $method, (int) round((float) $legacyRow['total_amount'] * 100), 'legacy_paid_order', (string) ($legacyRow['updated_at'] ?? '')]);
        }
    } catch (Throwable $e) {
        error_log(date(DATE_ATOM) . ' order payment schema: ' . $e->getMessage() . PHP_EOL, 3, BASE_PATH . '/storage/logs/operations-sync.log');
        return $ready = false;
    }
    return $ready = ops_table_exists('order_payment_allocations') && ops_table_exists('order_payment_allocation_audit');
}

function ops_order_payment_allocations(int $orderId): array
{
    if ($orderId <= 0 || !ops_ensure_order_payment_schema()) return [];
    $rows = ops_rows('SELECT payment_method, amount_cents, transaction_reference, source, source_version, updated_at FROM order_payment_allocations WHERE order_id = ? ORDER BY id', [$orderId]);
    return array_map(static fn(array $row): array => [
        'method' => (string) $row['payment_method'],
        'label' => ops_payment_label((string) $row['payment_method']),
        'amount_cents' => (int) $row['amount_cents'],
        'transaction_reference' => (string) ($row['transaction_reference'] ?? ''),
        'source' => (string) $row['source'],
        'version' => (string) $row['source_version'],
        'updated_at' => (string) $row['updated_at'],
    ], $rows);
}

function ops_wc_payment_allocations(array $order): array
{
    $candidates = [];
    $visit = static function ($value, string $key = '') use (&$visit, &$candidates): void {
        if (is_string($value) && (($value[0] ?? '') === '[' || ($value[0] ?? '') === '{')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
        }
        if (!is_array($value)) return;
        $methodRaw = $value['payment_method'] ?? $value['method'] ?? $value['gateway'] ?? $value['tender'] ?? $value['type'] ?? '';
        $amountRaw = $value['amount_cents'] ?? $value['amount'] ?? $value['value'] ?? $value['total'] ?? null;
        $method = ops_normalize_payment_code((string) $methodRaw);
        if ($method !== '' && $amountRaw !== null && is_numeric((string) $amountRaw)) {
            $isCents = array_key_exists('amount_cents', $value) || stripos($key, 'cent') !== false;
            $amountCents = $isCents ? (int) round((float) $amountRaw) : (int) round((float) $amountRaw * 100);
            if ($amountCents > 0) $candidates[$method] = ($candidates[$method] ?? 0) + $amountCents;
        }
        foreach ($value as $childKey => $child) $visit($child, $key . ' ' . (string) $childKey);
    };
    $paymentMeta = [];
    foreach ((array) ($order['meta_data'] ?? []) as $meta) {
        $paymentMeta[(string) ($meta['key'] ?? '')] = $meta['value'] ?? null;
    }
    if (array_key_exists('_hpos_payment_allocations', $paymentMeta)) {
        $visit($paymentMeta['_hpos_payment_allocations'], '_hpos_payment_allocations amount_cents');
    } elseif (array_key_exists('_hpos_split', $paymentMeta)) {
        $visit($paymentMeta['_hpos_split'], '_hpos_split amount');
    }
    if (!$candidates) {
        $method = ops_normalize_payment_code((string) (($order['payment_method'] ?? '') ?: ($order['payment_method_title'] ?? '')));
        $confirmedPaid = !empty($order['date_paid']) || in_array((string) ($order['status'] ?? ''), ['processing', 'completed'], true);
        if ($method !== '' && $confirmedPaid) $candidates[$method] = (int) round((float) ($order['total'] ?? 0) * 100);
    }
    return array_values(array_map(static fn(string $method, int $amount): array => ['method'=>$method, 'amount_cents'=>$amount], array_keys($candidates), array_values($candidates)));
}

function ops_wc_payment_source(array $order): string
{
    $meta = [];
    foreach ((array) ($order['meta_data'] ?? []) as $entry) {
        $meta[(string) ($entry['key'] ?? '')] = $entry['value'] ?? null;
    }
    $declared = strtolower(trim((string) ($meta['_hpos_payment_source'] ?? '')));
    if ($declared === 'order_list') return 'order_list';
    if (!empty($meta['_hpos_sale']) || array_key_exists('_hpos_split', $meta)) return 'pos';
    return 'woocommerce';
}

function ops_sync_order_payment_allocations(int $orderId, array $allocations, string $source, string $version): void
{
    if (!$allocations) return;
    $current = ops_row('SELECT payment_source, payment_version FROM ops_orders WHERE id = ? LIMIT 1', [$orderId]);
    $currentSource = (string) ($current['payment_source'] ?? 'woocommerce');
    if ((string) ($current['payment_version'] ?? '') === $version) return;
    if ($source === 'woocommerce' && in_array($currentSource, ['pos', 'order_list'], true)) return;
    if ($source === 'order_list' && $currentSource === 'pos' && (string) ($current['payment_version'] ?? '') !== $version) return;
    ops_replace_order_payment_allocations($orderId, $allocations, $source, $version);
}

function ops_replace_order_payment_allocations(int $orderId, array $allocations, string $source, string $version, ?int $employeeId = null): void
{
    if ($orderId <= 0 || !$allocations || !ops_ensure_order_payment_schema()) return;
    $previous = ops_order_payment_allocations($orderId);
    db()->prepare('DELETE FROM order_payment_allocations WHERE order_id = ?')->execute([$orderId]);
    $insert = db()->prepare('INSERT INTO order_payment_allocations (order_id, payment_method, amount_cents, transaction_reference, source, source_version, updated_by_employee_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($allocations as $allocation) {
        $insert->execute([$orderId, $allocation['method'], $allocation['amount_cents'], $allocation['transaction_reference'] ?? null, $source, $version, $employeeId]);
    }
    $summary = implode(' / ', array_map(static fn(array $allocation): string => ops_payment_label((string) $allocation['method']), $allocations));
    $totalCents = array_sum(array_column($allocations, 'amount_cents'));
    $order = ops_row('SELECT total_amount FROM ops_orders WHERE id = ? LIMIT 1', [$orderId]);
    $paid = $order && $totalCents >= (int) round((float) $order['total_amount'] * 100) ? 'paid' : ($totalCents > 0 ? 'partial' : 'unpaid');
    db()->prepare('UPDATE ops_orders SET payment_method = ?, payment_status = ?, payment_source = ?, payment_updated_at = CURRENT_TIMESTAMP, payment_updated_by_employee_id = ?, payment_version = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$summary, $paid, $source, $employeeId, $version, $orderId]);
    db()->prepare('INSERT INTO order_payment_allocation_audit (order_id, previous_allocations_json, new_allocations_json, changed_by_employee_id, source) VALUES (?, ?, ?, ?, ?)')->execute([$orderId, json_encode($previous, JSON_UNESCAPED_SLASHES), json_encode($allocations, JSON_UNESCAPED_SLASHES), $employeeId, $source]);
}

function ops_normalize_fulfilment_mode(?string $value): string
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[\s-]+/', '_', $value) ?? '';
    if ($value === '') return 'unknown';
    if (preg_match('/courier|nampost|nam_post|pudo|shipping|\bship\b/', $value)) return 'courier';
    if (preg_match('/local_pickup|pickup|pick_up|collection|collect/', $value)) return 'collection';
    if (preg_match('/delivery|local_delivery|flat_rate/', $value)) return 'delivery';
    return 'unknown';
}

function ops_pos_fulfilment_from_order(array $order): array
{
    $line = (array) (($order['shipping_lines'][0] ?? null) ?: []);
    $methodId = trim((string) ($line['method_id'] ?? ''));
    $methodTitle = trim((string) ($line['method_title'] ?? ''));
    $raw = trim($methodId . ' ' . $methodTitle);
    $mode = $raw === '' ? 'collection' : ops_normalize_fulfilment_mode($raw);
    return ['mode'=>$mode,'label'=>$mode === 'unknown' ? 'Not set' : ucfirst($mode),'source'=>'pos','raw'=>$raw];
}

function ops_resolve_order_fulfilment(array $order): array
{
    $candidates = [['pos',$order['pos_fulfilment_mode']??null],['pos',$order['fulfilment_mode']??null],['website',$order['order_type']??($order['website_shipping_mode']??null)]];
    foreach ($candidates as [$source,$raw]) {
        if ($raw === null || trim((string)$raw) === '') continue;
        $mode = ops_normalize_fulfilment_mode((string)$raw);
        if ($mode !== 'unknown') return ['mode'=>$mode,'label'=>ucfirst($mode),'source'=>$source,'raw'=>(string)$raw,'updated_at'=>$order['fulfilment_updated_at']??($order['updated_at']??null)];
    }
    return ['mode'=>'unknown','label'=>'Not set','source'=>'unknown','raw'=>'','updated_at'=>$order['fulfilment_updated_at']??null];
}

function ops_nav(string $active): void
{
    if (user_has_role('owner_admin')) {
        $items = [
            'index' => ['Dashboard', 'layout-dashboard', 'index.php'],
            'account' => ['My Account', 'key-round', 'my-account.php'],
            'board' => ['Orders Board', 'table-2', 'orders-board.php'],
            'orders' => ['Orders', 'shopping-bag', 'orders.php'],
            'whatsapp' => ['Meta Comms', 'messages-square', 'whatsapp.php'],
            'bookkeeping' => ['Bookkeeping', 'wallet-cards', 'bookkeeping.php'],
            'bank-processor' => ['Bank Processor', 'file-spreadsheet', 'bank-statement-processor.php'],
            'courier' => ['Courier', 'truck', 'courier.php'],
            'checklists' => ['Task Management', 'list-checks', 'checklists.php'],
            'errors' => ['Errors', 'triangle-alert', 'errors.php'],
            'barcode' => ['Barcode', 'scan-barcode', 'barcode.php'],
            'consignments' => ['Consignments', 'package-open', 'consignments.php'],
        ];
    } elseif (user_has_role('front_desk_admin')) {
        $items = [
            'account' => ['My Account', 'key-round', 'my-account.php'],
            'board' => ['Orders Board', 'table-2', 'orders-board.php'],
            'whatsapp' => ['Meta Comms', 'messages-square', 'whatsapp.php'],
            'bookkeeping' => ['Bookkeeping', 'wallet-cards', 'bookkeeping.php'],
            'bank-processor' => ['Bank Processor', 'file-spreadsheet', 'bank-statement-processor.php'],
            'courier' => ['Courier', 'truck', 'courier.php'],
            'checklists' => ['Task Management', 'list-checks', 'checklists.php'],
            'errors' => ['Errors', 'triangle-alert', 'errors.php'],
        ];
    } else {
        $items = [
            'account' => ['My Account', 'key-round', 'my-account.php'],
            'checklists' => ['Task Management', 'list-checks', 'checklists.php'],
            'courier' => ['Courier', 'truck', 'courier.php'],
            'barcode' => ['Barcode', 'scan-barcode', 'barcode.php'],
        ];
    }

    echo '<nav class="ops-nav" aria-label="Operations navigation">';
    foreach ($items as $key => [$label, $icon, $href]) {
        $class = $active === $key ? ' class="active"' : '';
        echo '<a' . $class . ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"><i data-lucide="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    echo '</nav>';
}

function ops_database_ready(): bool
{
    try {
        $stmt = db()->query('SELECT 1 FROM ops_orders LIMIT 1');
        if ($stmt) {
            $stmt->closeCursor();
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Ensure the append-only KPI evidence store exists without rewriting history. */
function ops_ensure_kpi_activity_events(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS kpi_activity_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                portal_section VARCHAR(80) NOT NULL,
                record_type VARCHAR(80) NOT NULL,
                record_id BIGINT NOT NULL,
                employee_id INT NULL,
                employee_name VARCHAR(160) NULL,
                action_key VARCHAR(120) NOT NULL,
                previous_status VARCHAR(120) NULL,
                new_status VARCHAR(120) NULL,
                occurred_at DATETIME NOT NULL,
                due_at DATETIME NULL,
                assigned_at DATETIME NULL,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                related_reference VARCHAR(190) NULL,
                source_page VARCHAR(190) NOT NULL,
                reason_note TEXT NULL,
                metadata_json LONGTEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_kpi_activity_record (portal_section, record_type, record_id, occurred_at),
                KEY idx_kpi_activity_employee (employee_id, occurred_at),
                KEY idx_kpi_activity_action (action_key, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $ready = ops_table_exists('kpi_activity_events');
    } catch (Throwable $error) {
        error_log(date(DATE_ATOM) . ' KPI activity schema: ' . $error->getMessage() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
        $ready = false;
    }
    return $ready;
}

/** Record one immutable, server-timestamped workflow action. */
function ops_kpi_record_event(string $section, string $recordType, int $recordId, string $action, ?string $previousStatus, ?string $newStatus, ?int $employeeId = null, array $context = []): void
{
    if ($recordId <= 0 || $section === '' || $recordType === '' || $action === '' || !ops_ensure_kpi_activity_events()) return;
    $employeeId = $employeeId ?: (function_exists('ops_current_employee_id') ? (ops_current_employee_id() ?: null) : null);
    $employeeName = null;
    if ($employeeId) {
        $employee = ops_row('SELECT full_name FROM ops_employees WHERE id = ? LIMIT 1', [$employeeId]);
        $employeeName = $employee ? (string) $employee['full_name'] : null;
    }
    $sourcePage = substr((string) ($context['source_page'] ?? ($_SERVER['PHP_SELF'] ?? 'server')), 0, 190);
    $metadata = $context['metadata'] ?? null;
    try {
        db()->prepare(
            'INSERT INTO kpi_activity_events
             (portal_section,record_type,record_id,employee_id,employee_name,action_key,previous_status,new_status,occurred_at,due_at,assigned_at,started_at,completed_at,related_reference,source_page,reason_note,metadata_json)
             VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?,?,?,?,?,?,?,?)'
        )->execute([
            substr($section, 0, 80), substr($recordType, 0, 80), $recordId, $employeeId, $employeeName,
            substr($action, 0, 120), $previousStatus, $newStatus,
            $context['due_at'] ?? null, $context['assigned_at'] ?? null, $context['started_at'] ?? null,
            $context['completed_at'] ?? null, isset($context['related_reference']) ? substr((string) $context['related_reference'], 0, 190) : null,
            $sourcePage, $context['reason_note'] ?? null,
            $metadata === null ? null : json_encode($metadata, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $error) {
        error_log(date(DATE_ATOM) . ' KPI activity event: ' . $error->getMessage() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
    }
}

function ops_setup_notice(): void
{
    echo '<section class="ops-alert"><strong>Database setup needed.</strong> Import <code>operations-migration.sql</code> into the portal database to activate saved orders, checklists, barcode logs, consignments, KPIs, petty cash and reports. The screens are ready, but live data is paused until the tables exist.</section>';
}

function ops_count(string $table, string $where = '1=1'): int
{
    try {
        $stmt = db()->query("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $count = (int) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

function ops_rows(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function ops_row(string $sql, array $params = []): ?array
{
    $rows = ops_rows($sql, $params);
    return $rows[0] ?? null;
}

function ops_order_datetime_columns(): array
{
    $columns = [];
    foreach (['displayed_order_datetime', 'order_datetime', 'date_created', 'created_at'] as $column) {
        if (ops_column_exists('ops_orders', $column)) {
            $columns[] = $column;
        }
    }

    return $columns ?: ['created_at'];
}

function ops_order_display_datetime_expr(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    $columns = array_map(static fn (string $column): string => $prefix . $column, ops_order_datetime_columns());

    return count($columns) === 1 ? $columns[0] : 'COALESCE(' . implode(', ', $columns) . ')';
}

function ops_order_display_datetime_update_column(): string
{
    $columns = ops_order_datetime_columns();

    return $columns[0] ?? 'created_at';
}

function ops_ensure_order_manual_order_table(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_order_manual_order (
            group_date DATE NOT NULL,
            order_id INT NOT NULL,
            sort_index INT NOT NULL,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (group_date, order_id),
            INDEX idx_order_manual_order_sort (group_date, sort_index),
            INDEX idx_order_manual_order_order (order_id)
        )"
    );

    $ready = true;
}

function ops_read_define_config(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $source = file_get_contents($path);
    if ($source === false) {
        return [];
    }

    $values = [];
    if (preg_match_all("/define\\(\\s*['\"]([A-Z_]+)['\"]\\s*,\\s*(['\"])(.*?)\\2\\s*\\)/", $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $values[$match[1]] = stripcslashes($match[3]);
        }
    }

    return $values;
}

function ops_secret_value($value): string
{
    $value = trim((string) $value);
    if ($value === '' || strpos($value, 'your_') === 0 || strpos($value, 'paste-') === 0) {
        return '';
    }

    return $value;
}

function ops_hr_db(): ?PDO
{
    static $pdo = false;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($pdo === null) {
        return null;
    }

    $pdo = null;
    try {
        $localConfigPath = BASE_PATH . '/config.local.php';
        $local = [];
        if (is_file($localConfigPath)) {
            $loaded = require $localConfigPath;
            if (is_array($loaded)) {
                $local = $loaded;
            }
        }

        $livePath = ops_secret_value(getenv('HAMBELELA_HR_LIVE_CONFIG') ?: ($local['hr_live_config_path'] ?? ''));
        if ($livePath === '') {
            $livePath = dirname(BASE_PATH) . '/hr.hambelelaorganic.com/config.php';
        }
        $live = ops_read_define_config($livePath);

        $host = ops_secret_value(getenv('HAMBELELA_HR_DB_HOST') ?: ($local['hr_db_host'] ?? '')) ?: ($live['DB_HOST'] ?? '');
        $name = ops_secret_value(getenv('HAMBELELA_HR_DB_NAME') ?: ($local['hr_db_name'] ?? '')) ?: ($live['DB_NAME'] ?? '');
        $user = ops_secret_value(getenv('HAMBELELA_HR_DB_USER') ?: ($local['hr_db_user'] ?? '')) ?: ($live['DB_USER'] ?? '');
        $pass = ops_secret_value(getenv('HAMBELELA_HR_DB_PASS') ?: ($local['hr_db_pass'] ?? '')) ?: ($live['DB_PASS'] ?? '');

        if ($host === '' || $name === '' || $user === '') {
            return null;
        }

        $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    } catch (Throwable $e) {
        $pdo = null;
        return null;
    }
}

function ops_hr_rows(string $sql, array $params = []): array
{
    $pdo = ops_hr_db();
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function ops_hr_employee_options(): array
{
    $rows = ops_hr_rows(
        "SELECT id, emp_number, first_name, last_name, email, job_title, department, basic_salary, status
         FROM employees
         ORDER BY status = 'active' DESC, first_name, last_name"
    );

    $map = [];
    foreach ($rows as $row) {
        $row['full_name'] = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        $map[(int) $row['id']] = $row;
    }

    return $map;
}

function ops_business_window(DateTimeImmutable $date): ?array
{
    $day = (int) $date->format('N');
    if ($day >= 1 && $day <= 5) {
        return [$date->setTime(8, 0), $date->setTime(17, 0)];
    }
    if ($day === 6) {
        return [$date->setTime(9, 0), $date->setTime(13, 0)];
    }

    return null;
}

function ops_next_business_open(DateTimeImmutable $date): DateTimeImmutable
{
    for ($i = 0; $i < 10; $i++) {
        $window = ops_business_window($date);
        if ($window) {
            [$open, $close] = $window;
            if ($date < $open) {
                return $open;
            }
            if ($date >= $open && $date < $close) {
                return $date;
            }
        }
        $date = $date->modify('+1 day')->setTime(8, 0);
    }

    return $date;
}

function ops_business_minutes_between(?string $from, ?string $to): ?float
{
    if (!$from || !$to) {
        return null;
    }

    try {
        $start = ops_next_business_open(new DateTimeImmutable($from));
        $end = new DateTimeImmutable($to);
    } catch (Throwable $e) {
        return null;
    }

    if ($end <= $start) {
        return 0.0;
    }

    $minutes = 0.0;
    $cursor = $start;
    for ($i = 0; $i < 370 && $cursor < $end; $i++) {
        $window = ops_business_window($cursor);
        if (!$window) {
            $cursor = $cursor->modify('+1 day')->setTime(8, 0);
            continue;
        }
        [$open, $close] = $window;
        $segmentStart = $cursor < $open ? $open : $cursor;
        $segmentEnd = $end < $close ? $end : $close;
        if ($segmentEnd > $segmentStart) {
            $minutes += ($segmentEnd->getTimestamp() - $segmentStart->getTimestamp()) / 60;
        }
        $cursor = $cursor->modify('+1 day')->setTime(8, 0);
    }

    return round($minutes, 1);
}

function ops_post_string(string $key, int $max = 255): string
{
    return substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
}

function ops_activity_log(string $action, string $entityType, int $entityId, array $metadata = []): void
{
    if (ops_table_exists('ops_activity_logs')) {
        try {
            $stmt = db()->prepare(
                "INSERT INTO ops_activity_logs (employee_id, action, entity_type, entity_id, metadata, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                ops_current_employee_id(),
                $action,
                $entityType,
                $entityId,
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Activity logging should never block the operational workflow.
        }
    }

    // Explicit Orders-module bridge only. EPI remains opt-in and can never block Orders.
    if ($entityType === 'order') {
        try {
            \Hambelela\EPI\OrdersActivityBridge::record(db(), $action, $entityId, $metadata);
        } catch (Throwable $e) {
            // Orders remains operational even if the background EPI subsystem is unavailable.
        }
    }

    // Explicit Packing-module bridge only. It is fail-safe, feature-flagged and runs on saves, never page load.
    if ($entityType === 'packing_task') {
        try {
            \Hambelela\EPI\PackingActivityBridge::record(db(), $action, $entityId, $metadata);
        } catch (Throwable $e) {
            // Packing remains operational even if background EPI recording is unavailable.
        }
    }

    // Explicit Task Management bridge. It runs only for saved task activity and is always fail-safe.
    if ($entityType === 'checklist_task') {
        try {
            \Hambelela\EPI\TaskActivityBridge::record(db(), $action, $entityId, $metadata);
        } catch (Throwable $e) {
            // Task Management must remain operational if background EPI recording is unavailable.
        }
    }
}

function ops_log_order_stage_event(int $orderId, string $stageKey, array $metadata = [], ?int $employeeId = null): void
{
    if ($orderId <= 0 || $stageKey === '' || !ops_table_exists('ops_order_stage_events')) {
        return;
    }

    try {
        $stmt = db()->prepare(
            "INSERT INTO ops_order_stage_events (order_id, stage_key, employee_id, metadata)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $orderId,
            $stageKey,
            $employeeId ?? ops_current_employee_id(),
            $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        // Stage tracking should support KPI reporting without blocking order work.
    }
}

function ops_is_valid_revenue_status(string $status, string $paymentStatus = ''): bool
{
    $status = strtolower($status);
    $paymentStatus = strtolower($paymentStatus);

    return !in_array($status, ['cancelled', 'canceled', 'refunded', 'failed', 'error_logged'], true)
        && !in_array($paymentStatus, ['refunded', 'cancelled', 'canceled', 'failed'], true);
}

function ops_workload_score(int $itemCount, string $orderType, int $complexity, string $priority): float
{
    $typePoints = ['collection' => 1.0, 'delivery' => 1.3, 'courier' => 1.6][$orderType] ?? 1.0;
    $priorityPoints = ['normal' => 1.0, 'urgent' => 1.25, 'same_day' => 1.45][$priority] ?? 1.0;

    return round(max(1, $itemCount) * max(1, $complexity) * $typePoints * $priorityPoints, 2);
}

function ops_is_walk_in_order(string $customerName, string $customerContact, string $orderType = ''): bool
{
    $normalise = static function (string $value): string {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?? '';
    };
    $type = $normalise($orderType);
    $contact = $normalise($customerContact);
    $name = $normalise($customerName);

    return in_array($type, ['walkin', 'walkincustomer'], true)
        || in_array($contact, ['walkin', 'walkincustomer'], true)
        || $name === 'walkincustomer';
}

function ops_secilia_front_desk_employee_id(): ?int
{
    $emailFilter = ops_column_exists('ops_employees', 'email')
        ? " OR LOWER(COALESCE(e.email, '')) = 'shiwedasecilia3@gmail.com'"
        : '';
    $rows = ops_rows(
        "SELECT e.id FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         WHERE e.status = 'active' AND r.role_key = 'front_desk_admin'
           AND (LOWER(TRIM(e.full_name)) = 'secilia shiweda'{$emailFilter})
         ORDER BY CASE WHEN LOWER(TRIM(e.full_name)) = 'secilia shiweda' THEN 0 ELSE 1 END, e.id
         LIMIT 2"
    );

    return count($rows) === 1 ? (int) $rows[0]['id'] : null;
}

function ops_initial_order_packer_id(string $customerName, string $customerContact, string $orderType = ''): ?int
{
    return ops_is_walk_in_order($customerName, $customerContact, $orderType)
        ? ops_secilia_front_desk_employee_id()
        : null;
}

function ops_log_initial_order_assignment(int $orderId, ?int $packerId, string $source): void
{
    if ($orderId <= 0 || !$packerId) {
        return;
    }
    $metadata = [
        'source' => $source,
        'previous_packer_id' => null,
        'previous_value' => 'Unassigned',
        'assigned_packer_id' => $packerId,
        'new_value' => 'Secilia Shiweda',
        'reason' => 'Walk-in Customer',
        'message' => 'Order automatically assigned to Secilia Shiweda (Walk-in Customer).',
    ];
    ops_log_order_stage_event($orderId, 'assigned', $metadata, $packerId);
    ops_activity_log('walk_in_auto_assigned', 'order', $orderId, $metadata);
    notifications_notify_order_assigned($orderId, $packerId);
}

function ops_column_exists(string $table, string $column): bool
{
    $stmt = null;
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $exists = (int) $stmt->fetchColumn() > 0;

        return $exists;
    } catch (Throwable $e) {
        return false;
    } finally {
        if ($stmt instanceof PDOStatement) {
            $stmt->closeCursor();
        }
    }
}

function ops_ensure_packing_assignable_column(): bool
{
    if (!ops_table_exists('ops_employees')) {
        return false;
    }
    if (ops_column_exists('ops_employees', 'packing_assignable')) {
        return true;
    }

    try {
        db()->exec('ALTER TABLE ops_employees ADD COLUMN packing_assignable TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
        db()->exec(
            "UPDATE ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             SET e.packing_assignable = 1
             WHERE r.role_key IN ('packer', 'supervisor_manager')"
        );
    } catch (Throwable $e) {
        return false;
    }

    return ops_column_exists('ops_employees', 'packing_assignable');
}

function ops_ensure_packing_website_workflow_columns(): bool
{
    if (!ops_table_exists('ops_packing_tasks')) {
        return false;
    }

    $columns = [
        'packing_website_completed_at' => 'DATETIME NULL',
        'packing_website_completed_by' => 'INT NULL',
        'frontdesk_website_updated' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'frontdesk_website_updated_at' => 'DATETIME NULL',
        'frontdesk_website_updated_by' => 'INT NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (ops_column_exists('ops_packing_tasks', $column)) {
            continue;
        }
        try {
            db()->exec("ALTER TABLE ops_packing_tasks ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            return false;
        }
    }

    foreach (array_keys($columns) as $column) {
        if (!ops_column_exists('ops_packing_tasks', $column)) {
            return false;
        }
    }
    return true;
}

function ops_ensure_packing_auto_assignable_column(): bool
{
    if (!ops_ensure_packing_assignable_column()) {
        return false;
    }
    if (ops_column_exists('ops_employees', 'packing_auto_assignable')) {
        return true;
    }

    try {
        db()->exec('ALTER TABLE ops_employees ADD COLUMN packing_auto_assignable TINYINT(1) NOT NULL DEFAULT 0 AFTER packing_assignable');
        db()->exec(
            "UPDATE ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             SET e.packing_assignable = 1
             WHERE e.status = 'active'
               AND r.role_key IN ('packer', 'supervisor_manager', 'front_desk_admin', 'owner_admin')"
        );
        db()->exec(
            "UPDATE ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             SET e.packing_auto_assignable = 1
             WHERE e.status = 'active'
               AND e.packing_assignable = 1
               AND r.role_key IN ('packer', 'supervisor_manager')"
        );
    } catch (Throwable $e) {
        return false;
    }

    return ops_column_exists('ops_employees', 'packing_auto_assignable');
}

function ops_employee_can_receive_packing(int $employeeId, bool $automatic = false): bool
{
    if ($employeeId <= 0 || !ops_ensure_packing_auto_assignable_column()) {
        return false;
    }

    $column = $automatic ? 'packing_auto_assignable' : 'packing_assignable';
    $rows = ops_rows(
        "SELECT id FROM ops_employees WHERE id = ? AND status = 'active' AND {$column} = 1 LIMIT 1",
        [$employeeId]
    );

    return !empty($rows);
}

function ops_table_exists(string $table): bool
{
    $stmt = null;
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $exists = (int) $stmt->fetchColumn() > 0;

        return $exists;
    } catch (Throwable $e) {
        return false;
    } finally {
        if ($stmt instanceof PDOStatement) {
            $stmt->closeCursor();
        }
    }
}

function ops_current_employee_id(): ?int
{
    $user = current_user();
    $id = (int) ($user['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function ops_task_scope_for_current_user(): array
{
    if (user_has_role('owner_admin')) {
        return ['type' => 'all', 'employee_id' => null];
    }
    $employeeId = ops_current_employee_id();
    return ['type' => 'assigned', 'employee_id' => $employeeId && $employeeId > 0 ? $employeeId : null];
}

function ops_current_user_can_access_task(int $taskId): bool
{
    if ($taskId <= 0) return false;
    $scope = ops_task_scope_for_current_user();
    if ($scope['type'] === 'all') return true;
    if (!$scope['employee_id']) return false;
    return (bool) ops_row('SELECT id FROM ops_checklist_tasks WHERE id = ? AND assigned_employee_id = ? AND deleted_at IS NULL LIMIT 1', [$taskId, $scope['employee_id']]);
}

function ops_can_update_order_paid_status(): bool
{
    $roleKey = current_role_key();
    return $roleKey !== 'guest' && portal_role_can_access_feature($roleKey, 'orders');
}

function ops_can_update_order_payment_method(): bool
{
    return user_has_role('owner_admin', 'front_desk_admin', 'front_desk_admin_employee');
}

function ops_staff_text_key(array $employee): string
{
    return strtolower(trim(implode(' ', [
        (string) ($employee['full_name'] ?? ''),
        (string) ($employee['name'] ?? ''),
        (string) ($employee['employee_name'] ?? ''),
        (string) ($employee['email'] ?? ''),
        (string) ($employee['username'] ?? ''),
    ])));
}

function ops_is_cecilia_employee(array $employee): bool
{
    $text = ops_staff_text_key($employee);

    return strpos($text, 'cecil') !== false
        || strpos($text, 'secil') !== false
        || strpos($text, 'shiweda') !== false;
}

function ops_is_generic_front_desk_employee(array $employee): bool
{
    $roleKey = strtolower(trim((string) ($employee['role_key'] ?? '')));
    if ($roleKey !== 'front_desk_admin') {
        return false;
    }

    $text = preg_replace('/[^a-z0-9]+/', '', ops_staff_text_key($employee));

    return $text === ''
        || strpos($text, 'frontdeskadmin') !== false
        || strpos($text, 'frontdesk') !== false
        || strpos($text, 'frontperson') !== false
        || strpos($text, 'adminemployee') !== false;
}

function ops_staff_display_name(array $employee): string
{
    $text = ops_staff_text_key($employee);
    if (ops_is_cecilia_employee($employee) || ops_is_generic_front_desk_employee($employee)) {
        return 'Secilia Shiweda';
    }
    if (strpos($text, 'victoria') !== false) {
        return 'Victoria Toivo';
    }
    if (strpos($text, 'klaudia') !== false) {
        return 'Klaudia Averinus';
    }
    if (strpos($text, 'ndinelao') !== false || strpos($text, 'ndine') !== false) {
        return 'Ndinelao Kalola';
    }

    return trim((string) ($employee['full_name'] ?? $employee['name'] ?? $employee['employee_name'] ?? ''));
}

function ops_is_owner_employee(array $employee): bool
{
    return (string) ($employee['role_key'] ?? '') === 'owner_admin'
        || strpos(ops_staff_text_key($employee), 'victoria') !== false;
}

function ops_staff_role_label(array $employee): string
{
    $roleKey = (string) ($employee['role_key'] ?? '');
    if ($roleKey === 'owner_admin') return 'Owner/Admin';
    if ($roleKey === 'front_desk_admin') return 'Front Desk/Admin Employee';
    if ($roleKey === 'packer') return 'Packer/Production Staff';
    if ($roleKey === 'supervisor_manager') return 'Supervisor/Manager';

    return (string) ($employee['role_name'] ?? $employee['role'] ?? $roleKey);
}

function ops_employee_alias_map(array $employees): array
{
    $map = [];
    $ceciliaId = 0;
    $genericFrontDeskIds = [];

    foreach ($employees as $employee) {
        $id = (int) ($employee['id'] ?? $employee['employee_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $map[$id] = $id;
        if (ops_is_cecilia_employee($employee) && !ops_is_generic_front_desk_employee($employee)) {
            $ceciliaId = $id;
        } elseif (ops_is_generic_front_desk_employee($employee)) {
            $genericFrontDeskIds[] = $id;
            if (!$ceciliaId) {
                $ceciliaId = $id;
            }
        }
    }

    foreach ($genericFrontDeskIds as $genericId) {
        $map[$genericId] = $ceciliaId ?: $genericId;
    }

    return $map;
}

function ops_canonical_employee_rows(array $employees, bool $includeOwner = false): array
{
    $aliasMap = ops_employee_alias_map($employees);
    $rows = [];

    foreach ($employees as $employee) {
        $id = (int) ($employee['id'] ?? $employee['employee_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $canonicalId = (int) ($aliasMap[$id] ?? $id);
        $isGeneric = ops_is_generic_front_desk_employee($employee);
        $employee['id'] = $canonicalId;
        $employee['employee_id'] = $canonicalId;
        $employee['full_name'] = ops_staff_display_name($employee);
        $employee['role_name'] = ops_staff_role_label($employee);
        if (!$includeOwner && ops_is_owner_employee($employee)) {
            continue;
        }

        if (!isset($rows[$canonicalId]) || ($isGeneric === false && ops_is_generic_front_desk_employee($rows[$canonicalId]))) {
            $rows[$canonicalId] = $employee;
        }
    }

    return array_values($rows);
}

function ops_canonical_employee_id(int $employeeId, array $aliasMap): int
{
    return (int) ($aliasMap[$employeeId] ?? $employeeId);
}

function ops_reconcile_front_desk_employee(): void
{
    if (!ops_table_exists('ops_employees') || !ops_table_exists('ops_roles')) {
        return;
    }

    try {
        $rows = ops_rows(
            "SELECT e.id, e.full_name, e.email, e.status, r.role_key
             FROM ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             WHERE r.role_key = 'front_desk_admin'"
        );
        if (!$rows) {
            return;
        }

        $ceciliaId = 0;
        $genericIds = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (ops_is_cecilia_employee($row) && !ops_is_generic_front_desk_employee($row)) {
                $ceciliaId = $id;
            } elseif (ops_is_generic_front_desk_employee($row)) {
                $genericIds[] = $id;
            }
        }

        if (!$ceciliaId && $genericIds) {
            $stmt = db()->prepare("UPDATE ops_employees SET full_name = 'Secilia Shiweda', status = 'active' WHERE id = ?");
            $stmt->execute([(int) $genericIds[0]]);
            $stmt->closeCursor();
            return;
        }

        if (!$ceciliaId || !$genericIds) {
            return;
        }

        $referenceColumns = [
            'ops_orders' => ['assigned_packer_id', 'assigned_verifier_id', 'created_by'],
            'ops_order_items' => ['packed_by'],
            'ops_order_stage_events' => ['employee_id'],
            'ops_checklist_tasks' => ['assigned_employee_id', 'completed_by', 'created_by'],
            'ops_packing_tasks' => ['assigned_employee_id', 'inventory_updated_by', 'created_by'],
            'ops_error_logs' => ['employee_id', 'logged_by'],
            'ops_cash_transactions' => ['recorded_by'],
            'ops_courier_waybills' => ['uploaded_by', 'sent_by'],
            'ops_employee_availability' => ['employee_id'],
            'ops_employee_availability_history' => ['employee_id'],
        ];
        $placeholders = implode(',', array_fill(0, count($genericIds), '?'));
        foreach ($referenceColumns as $table => $columns) {
            if (!ops_table_exists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (!ops_column_exists($table, $column)) {
                    continue;
                }
                try {
                    $stmt = db()->prepare("UPDATE {$table} SET {$column} = ? WHERE {$column} IN ({$placeholders})");
                    $stmt->execute(array_merge([$ceciliaId], $genericIds));
                    $stmt->closeCursor();
                } catch (Throwable $e) {
                    error_log("Front desk reference merge skipped for {$table}.{$column}: " . $e->getMessage());
                }
            }
        }

        $stmt = db()->prepare("UPDATE ops_employees SET status = 'inactive' WHERE id IN ({$placeholders})");
        $stmt->execute($genericIds);
        $stmt->closeCursor();
    } catch (Throwable $e) {
        error_log('Front desk employee reconciliation failed: ' . $e->getMessage());
    }
}

function ops_reconcile_core_staff(): void
{
    if (!ops_table_exists('ops_employees') || !ops_table_exists('ops_roles')) {
        return;
    }

    ops_reconcile_front_desk_employee();

    $targets = [
        [
            'email' => 'shiwedasecilia3@gmail.com',
            'name' => 'Secilia Shiweda',
            'first' => 'Secilia',
            'last' => 'Shiweda',
            'role' => 'front_desk_admin',
            'match' => ['cecil', 'secil', 'shiweda'],
        ],
        [
            'email' => null,
            'name' => 'Klaudia Averinus',
            'first' => 'Klaudia',
            'last' => 'Averinus',
            'role' => 'packer',
            'match' => ['klaudia'],
        ],
        [
            'email' => null,
            'name' => 'Ndinelao Kalola',
            'first' => 'Ndinelao',
            'last' => 'Kalola',
            'role' => 'packer',
            'match' => ['ndinelao', 'ndine'],
        ],
    ];

    try {
        foreach ($targets as $target) {
            $conditions = [];
            $params = [];
            if (!empty($target['email']) && ops_column_exists('ops_employees', 'email')) {
                $conditions[] = 'LOWER(e.email) = LOWER(?)';
                $params[] = $target['email'];
            }
            foreach ($target['match'] as $needle) {
                $conditions[] = 'LOWER(e.full_name) LIKE ?';
                $params[] = '%' . $needle . '%';
            }
            if (!$conditions) {
                continue;
            }

            $rows = ops_rows(
                "SELECT e.id, r.role_key
                 FROM ops_employees e
                 JOIN ops_roles r ON r.id = e.role_id
                 WHERE " . implode(' OR ', $conditions) . "
                 ORDER BY e.id ASC
                 LIMIT 1",
                $params
            );
            if (!$rows) {
                continue;
            }

            $employeeId = (int) $rows[0]['id'];
            $roleRows = ops_rows('SELECT id FROM ops_roles WHERE role_key = ? LIMIT 1', [$target['role']]);
            $roleId = $roleRows ? (int) $roleRows[0]['id'] : 0;
            $sets = ["full_name = ?", "status = 'active'"];
            $values = [$target['name']];
            if ($roleId > 0) {
                $sets[] = 'role_id = ?';
                $values[] = $roleId;
            }
            if (!empty($target['email']) && ops_column_exists('ops_employees', 'email')) {
                $sets[] = 'email = ?';
                $values[] = $target['email'];
            }
            if (ops_column_exists('ops_employees', 'first_name')) {
                $sets[] = 'first_name = ?';
                $values[] = $target['first'];
            }
            if (ops_column_exists('ops_employees', 'last_name')) {
                $sets[] = 'last_name = ?';
                $values[] = $target['last'];
            }
            $values[] = $employeeId;
            $stmt = db()->prepare('UPDATE ops_employees SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($values);
            $stmt->closeCursor();
        }

        if (ops_column_exists('ops_employees', 'email')) {
            $stmt = db()->prepare("UPDATE ops_employees SET status = 'active' WHERE LOWER(email) = LOWER(?)");
            $stmt->execute(['shiwedasecilia3@gmail.com']);
            $stmt->closeCursor();
        }
    } catch (Throwable $e) {
        error_log('Core staff reconciliation failed: ' . $e->getMessage());
    }
}

function ops_flash(?string $message, string $type = 'success'): void
{
    if (!$message) {
        return;
    }

    $label = $type === 'error' ? 'Could not save' : 'Saved';
    echo '<section class="ops-alert"><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '.</strong> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</section>';
}

function ops_select_options(array $options, string $selected = ''): void
{
    foreach ($options as $value => $label) {
        $isSelected = $value === $selected ? ' selected' : '';
        echo '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
}

function ops_employee_options(?int $selected = null, bool $includeBlank = true): void
{
    if ($includeBlank) {
        echo '<option value="">Unassigned</option>';
    }

    $employees = ops_rows(
        "SELECT e.id, e.full_name, r.role_key
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         WHERE e.status = 'active'
         ORDER BY FIELD(r.role_key, 'packer', 'supervisor_manager', 'front_desk_admin', 'owner_admin'), e.full_name"
    );

    foreach (ops_canonical_employee_rows($employees) as $employee) {
        $id = (int) $employee['id'];
        $label = ops_staff_display_name($employee);
        $isSelected = $selected !== null && $selected === $id ? ' selected' : '';
        echo '<option value="' . $id . '"' . $isSelected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
}

function ops_duration_label(?string $start, ?string $end): string
{
    if (!$start || !$end) {
        return '-';
    }

    try {
        $startAt = new DateTimeImmutable($start);
        $endAt = new DateTimeImmutable($end);
    } catch (Throwable $e) {
        return '-';
    }

    $seconds = max(0, $endAt->getTimestamp() - $startAt->getTimestamp());
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }

    return $minutes . 'm';
}

function ops_best_packer_for_packing(float $workloadPoints): ?int
{
    if (!ops_ensure_packing_auto_assignable_column()) {
        return null;
    }
    $rows = ops_rows(
        "SELECT e.id,
            COALESCE(SUM(pt.workload_points), 0) AS load_score,
            COUNT(pt.id) AS active_items
         FROM ops_employees e
         LEFT JOIN ops_packing_tasks pt ON pt.assigned_employee_id = e.id
           AND pt.packing_status NOT IN ('done', 'done_needs_label', 'label_created', 'website')
         LEFT JOIN ops_employee_availability ea ON ea.employee_id = e.id
         WHERE e.status = 'active' AND e.packing_auto_assignable = 1
           AND (
             ea.employee_id IS NULL
             OR ea.availability_status = 'available'
             OR (ea.unavailable_until IS NOT NULL AND ea.unavailable_until <= NOW())
         )
         GROUP BY e.id
         ORDER BY load_score ASC, active_items ASC, e.id ASC
         LIMIT 1"
    );

    return $rows ? (int) $rows[0]['id'] : null;
}
