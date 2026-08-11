<?php
declare(strict_types=1);

function cw_install_schema(PDO $pdo): void
{
    $version = 0;
    try {
        $version = (int) $pdo->query("SELECT setting_value FROM cw_settings WHERE setting_key='schema_version'")->fetchColumn();
    } catch (Throwable $e) {
        $version = 0;
    }
    if ($version < 1) {
        $sql = file_get_contents(BASE_PATH . '/apps/cost-manager/cost-workbook-migration.sql');
        if ($sql === false) throw new RuntimeException('Cost Workbook migration file is unavailable.');
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
            $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
            if ($statement !== '') $pdo->exec($statement);
        }
        $version = 2;
    }
    if ($version < 2) cw_upgrade_sync_schema_v2($pdo);
}

const CW_SYNC_STALE_SECONDS = 600; // Ten minutes: safely above normal batch time, short enough for abandoned-tab recovery.
const CW_SYNC_LOCK_NAME = 'hambelela_cost_workbook_website_sync';
// WooCommerce v3 returns the complete product payload. Keep reads small and give
// this administrator-triggered snapshot enough time for occasional slow pages.
const CW_SYNC_BATCH_SIZE = 10;
const CW_SYNC_READ_ATTEMPTS = 2;
const CW_SYNC_READ_TIMEOUT = 25;
const CW_SYNC_FIELDS = 'id,name,type,sku,categories,attributes,regular_price,sale_price,price,stock_quantity,stock_status,manage_stock,status,permalink';

function cw_sync_wc_get(string $path, array $query): array
{
    $lastError = null;
    for ($attempt = 1; $attempt <= CW_SYNC_READ_ATTEMPTS; $attempt++) {
        try { return wc_get($path, $query, CW_SYNC_READ_TIMEOUT); }
        catch (Throwable $e) { $lastError = $e; if ($attempt < CW_SYNC_READ_ATTEMPTS) usleep(250000); }
    }
    throw new RuntimeException('Website catalogue read failed after a bounded retry.', 0, $lastError);
}

function cw_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function cw_upgrade_sync_schema_v2(PDO $pdo): void
{
    $columns = [
        'sync_uuid' => "ADD COLUMN sync_uuid CHAR(36) NULL AFTER id",
        'queued_at' => "ADD COLUMN queued_at DATETIME NULL AFTER started_by_name",
        'heartbeat_at' => "ADD COLUMN heartbeat_at DATETIME NULL AFTER started_at",
        'updated_at' => "ADD COLUMN updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER heartbeat_at",
        'failed_at' => "ADD COLUMN failed_at DATETIME NULL AFTER completed_at",
        'recovered_at' => "ADD COLUMN recovered_at DATETIME NULL AFTER failed_at",
        'cancelled_at' => "ADD COLUMN cancelled_at DATETIME NULL AFTER recovered_at",
        'current_batch' => "ADD COLUMN current_batch INT NOT NULL DEFAULT 0 AFTER next_page",
        'current_offset' => "ADD COLUMN current_offset INT NOT NULL DEFAULT 0 AFTER current_batch",
        'expected_total' => "ADD COLUMN expected_total INT NULL AFTER total_products",
        'processed_count' => "ADD COLUMN processed_count INT NOT NULL DEFAULT 0 AFTER expected_total",
        'failure_reason' => "ADD COLUMN failure_reason VARCHAR(500) NULL AFTER error_message",
        'recovery_count' => "ADD COLUMN recovery_count INT NOT NULL DEFAULT 0 AFTER failure_reason",
        'recovery_reason' => "ADD COLUMN recovery_reason VARCHAR(500) NULL AFTER recovery_count",
        'recovered_by' => "ADD COLUMN recovered_by BIGINT NULL AFTER recovery_reason",
        'recovered_by_name' => "ADD COLUMN recovered_by_name VARCHAR(190) NOT NULL DEFAULT '' AFTER recovered_by",
        'is_successful_snapshot' => "ADD COLUMN is_successful_snapshot TINYINT(1) NOT NULL DEFAULT 0 AFTER recovered_by_name",
        'previous_successful_batch_id' => "ADD COLUMN previous_successful_batch_id BIGINT UNSIGNED NULL AFTER is_successful_snapshot",
        'last_batch_started_at' => "ADD COLUMN last_batch_started_at DATETIME NULL AFTER previous_successful_batch_id",
    ];
    foreach ($columns as $column => $sql) {
        if (!cw_column_exists($pdo, 'cw_sync_batches', $column)) $pdo->exec('ALTER TABLE cw_sync_batches ' . $sql);
    }
    $pdo->exec("ALTER TABLE cw_sync_batches MODIFY status ENUM('queued','running','complete','completed','failed','stale','recovered','cancelled') NOT NULL DEFAULT 'queued'");
    $pdo->exec("UPDATE cw_sync_batches SET status='completed' WHERE status='complete'");
    $pdo->exec("UPDATE cw_sync_batches SET sync_uuid=CONCAT('legacy-',id), queued_at=COALESCE(queued_at,started_at), heartbeat_at=COALESCE(heartbeat_at,updated_at,started_at), processed_count=COALESCE(processed_count,success_count), current_batch=GREATEST(current_batch,next_page-1) WHERE sync_uuid IS NULL OR sync_uuid=''");
    $pdo->exec('ALTER TABLE cw_sync_batches MODIFY sync_uuid CHAR(36) NOT NULL');
    $pdo->exec("UPDATE cw_sync_batches SET is_successful_snapshot=0");
    $pdo->exec("UPDATE cw_sync_batches SET is_successful_snapshot=1 WHERE id=(SELECT selected.id FROM (SELECT id FROM cw_sync_batches WHERE status='completed' ORDER BY completed_at DESC,id DESC LIMIT 1) selected)");
    try { $pdo->exec('ALTER TABLE cw_sync_batches ADD UNIQUE KEY uniq_cw_sync_uuid (sync_uuid)'); } catch (Throwable $e) { /* Idempotent upgrade. */ }
    try { $pdo->exec('ALTER TABLE cw_sync_batches ADD KEY idx_cw_sync_status (status,heartbeat_at)'); } catch (Throwable $e) { /* Idempotent upgrade. */ }
    try { $pdo->exec('ALTER TABLE cw_sync_batches ADD KEY idx_cw_sync_successful (is_successful_snapshot,completed_at)'); } catch (Throwable $e) { /* Idempotent upgrade. */ }
    $stmt = $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','2','system') ON DUPLICATE KEY UPDATE setting_value='2'");
    $stmt->execute();
}

function cw_sync_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function cw_sync_timestamp(array $sync): ?string
{
    foreach (['heartbeat_at', 'updated_at', 'started_at'] as $key) if (!empty($sync[$key])) return (string) $sync[$key];
    return null;
}

function cw_sync_is_stale(array $sync, ?int $now = null): bool
{
    if (($sync['status'] ?? '') !== 'running') return false;
    $timestamp = cw_sync_timestamp($sync);
    if ($timestamp === null) return true;
    $parsed = strtotime($timestamp . ' UTC');
    return $parsed === false || (($now ?? time()) - $parsed) > CW_SYNC_STALE_SECONDS;
}

function cw_sync_acquire_lock(PDO $pdo, string $suffix = '', int $timeout = 5): string
{
    $name = CW_SYNC_LOCK_NAME . $suffix;
    $stmt = $pdo->prepare('SELECT GET_LOCK(?,?)');
    $stmt->execute([$name, $timeout]);
    if ((int) $stmt->fetchColumn() !== 1) throw new RuntimeException('Website synchronization is busy. Please try again shortly.');
    return $name;
}

function cw_sync_release_lock(PDO $pdo, string $name): void
{
    try { $stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$name]); } catch (Throwable $e) { /* Connection close also releases advisory locks. */ }
}

function cw_sync_public(array $sync): array
{
    return [
        'id'=>(int)$sync['id'],'sync_uuid'=>(string)($sync['sync_uuid']??''),'status'=>(string)$sync['status'],
        'started_at'=>$sync['started_at']??null,'heartbeat_at'=>cw_sync_timestamp($sync),'completed_at'=>$sync['completed_at']??null,
        'failed_at'=>$sync['failed_at']??null,'recovered_at'=>$sync['recovered_at']??null,'current_batch'=>(int)($sync['current_batch']??0),
        'current_offset'=>(int)($sync['current_offset']??0),'processed_count'=>(int)($sync['processed_count']??0),
        'success_count'=>(int)($sync['success_count']??0),'error_count'=>(int)($sync['error_count']??0),
        'failure_reason'=>$sync['failure_reason']??null,'recovery_reason'=>$sync['recovery_reason']??null,
        'recovery_count'=>(int)($sync['recovery_count']??0),'recovered_by_name'=>$sync['recovered_by_name']??null,'is_stale'=>cw_sync_is_stale($sync),
        'is_successful_snapshot'=>(bool)($sync['is_successful_snapshot']??false),
    ];
}

function cw_csrf_token(): string
{
    if (empty($_SESSION['cw_csrf'])) $_SESSION['cw_csrf'] = bin2hex(random_bytes(24));
    return (string) $_SESSION['cw_csrf'];
}

function cw_require_csrf(): void
{
    $token = (string) ($_SERVER['HTTP_X_CW_CSRF'] ?? $_POST['csrf'] ?? '');
    if (!hash_equals(cw_csrf_token(), $token)) throw new RuntimeException('Your session token expired. Refresh and try again.');
}

function cw_require_admin(): void
{
    if (!user_has_role('owner_admin', 'supervisor_manager')) {
        http_response_code(403);
        throw new RuntimeException('Owner or admin permission is required.');
    }
}

function cw_user(): array
{
    $u = current_user();
    return ['id' => isset($u['id']) ? (int) $u['id'] : null, 'name' => (string) ($u['name'] ?? 'Unknown')];
}

function cw_decimal($value): ?string
{
    if ($value === null || $value === '') return null;
    $value = str_replace([',', ' '], '', (string) $value);
    if (!is_numeric($value)) return null;
    return number_format((float) $value, 6, '.', '');
}

function cw_nonnegative_amount_cents($value, string $label): int
{
    $raw = trim((string) $value);
    if ($raw === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $raw)) {
        throw new InvalidArgumentException($label . ' must be a valid non-negative number.');
    }
    return (int) round((float) $raw * 100, 0, PHP_ROUND_HALF_UP);
}

function cw_calculate_invoice_line($quantity, $unitPrice, $discount, $vatAmount, string $vatTreatment): array
{
    $quantityRaw = trim((string) $quantity);
    if ($quantityRaw === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $quantityRaw) || (float) $quantityRaw <= 0) {
        throw new InvalidArgumentException('Quantity must be greater than zero.');
    }
    $unitPriceRaw = trim((string) $unitPrice);
    if ($unitPriceRaw === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $unitPriceRaw)) {
        throw new InvalidArgumentException('Unit cost must be a valid non-negative number.');
    }
    $grossCents = (int) round((float) $quantityRaw * (float) $unitPriceRaw * 100, 0, PHP_ROUND_HALF_UP);
    $discountCents = cw_nonnegative_amount_cents($discount === null || $discount === '' ? '0' : $discount, 'Discount');
    if ($discountCents > $grossCents) {
        throw new InvalidArgumentException('Discount cannot exceed the gross line amount.');
    }
    $vatCents = cw_nonnegative_amount_cents($vatAmount === null || $vatAmount === '' ? '0' : $vatAmount, 'VAT amount');
    $discountedCents = $grossCents - $discountCents;
    if ($vatTreatment === 'inclusive') {
        if ($vatCents > $discountedCents) throw new InvalidArgumentException('VAT amount cannot exceed the discounted VAT-inclusive line amount.');
        $subtotalCents = $discountedCents - $vatCents;
        $totalCents = $discountedCents;
    } elseif ($vatTreatment === 'exempt') {
        if ($vatCents !== 0) throw new InvalidArgumentException('VAT amount must be zero for VAT-exempt invoices.');
        $subtotalCents = $discountedCents;
        $totalCents = $discountedCents;
    } else {
        $subtotalCents = $discountedCents;
        $totalCents = $discountedCents + $vatCents;
    }
    $money = static function (int $cents): string { return number_format($cents / 100, 2, '.', ''); };
    return [
        'gross' => $money($grossCents),
        'discount' => $money($discountCents),
        'line_subtotal' => $money($subtotalCents),
        'vat_amount' => $money($vatCents),
        'line_total' => $money($totalCents),
    ];
}

function cw_normalize_quantity($quantity, string $unit, $packSize = null): array
{
    $q = cw_decimal($quantity);
    if ($q === null || (float) $q <= 0) return [null, null, 'Quantity must be greater than zero.'];
    $u = strtolower(trim($unit));
    $map = ['kg' => ['g', 1000], 'g' => ['g', 1], 'l' => ['ml', 1000], 'ml' => ['ml', 1], 'unit' => ['unit', 1]];
    if (isset($map[$u])) return [number_format((float) $q * $map[$u][1], 6, '.', ''), $map[$u][0], null];
    if ($u === 'pack') {
        $size = cw_decimal($packSize);
        if ($size === null || (float) $size <= 0) return [null, 'pack', 'Pack size must be confirmed before conversion.'];
        return [number_format((float) $q * (float) $size, 6, '.', ''), 'unit', null];
    }
    return [null, null, 'Unit is not recognized.'];
}

function cw_calculate($priceIncVat, $cost, $vatRate, $targetMargin = null): array
{
    $price = cw_decimal($priceIncVat); $unitCost = cw_decimal($cost); $vat = cw_decimal($vatRate); $target = cw_decimal($targetMargin);
    $out = ['selling_ex_vat'=>null,'output_vat'=>null,'gross_profit'=>null,'gross_margin'=>null,'markup'=>null,'recommended_price_inc_vat'=>null,'status'=>'Missing price'];
    $vatBasisPoints = $vat === null ? null : (int) round((float) $vat * 100);
    if ($price === null || $vatBasisPoints === null || 10000 + $vatBasisPoints <= 0) return $out;
    $priceCents = (int) round((float) $price * 100);
    $sellingExVatCents = cw_round_divide($priceCents * 10000, 10000 + $vatBasisPoints);
    $out['selling_ex_vat'] = $sellingExVatCents / 100;
    $out['output_vat'] = ($priceCents - $sellingExVatCents) / 100;
    if ($unitCost === null) { $out['status'] = 'Missing cost'; return $out; }
    $costCents = (int) round((float) $unitCost * 100);
    $profitCents = $sellingExVatCents - $costCents;
    $out['gross_profit'] = $profitCents / 100;
    $out['gross_margin'] = $sellingExVatCents !== 0 ? cw_round_divide($profitCents * 10000, $sellingExVatCents) / 100 : null;
    $out['markup'] = $costCents !== 0 ? cw_round_divide($profitCents * 10000, $costCents) / 100 : null;
    $targetBasisPoints = $target === null ? null : (int) round((float) $target * 100);
    if ($targetBasisPoints !== null && $targetBasisPoints < 10000) {
        $out['recommended_price_inc_vat'] = cw_round_divide($costCents * (10000 + $vatBasisPoints), 10000 - $targetBasisPoints) / 100;
    }
    $out['status'] = $profitCents < 0 ? 'Below cost' : ($targetBasisPoints !== null && $out['gross_margin'] * 100 < $targetBasisPoints ? 'Below target' : ($targetBasisPoints !== null && (int) round($out['gross_margin'] * 100) === $targetBasisPoints ? 'At target' : 'Healthy margin'));
    return $out;
}

function cw_round_divide(int $numerator, int $denominator): int
{
    if ($denominator === 0) throw new InvalidArgumentException('Cannot divide by zero.');
    $sign = ($numerator < 0) xor ($denominator < 0) ? -1 : 1;
    return $sign * intdiv(abs($numerator) + intdiv(abs($denominator), 2), abs($denominator));
}
