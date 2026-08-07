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
    if ($version >= 1) return;
    $sql = file_get_contents(BASE_PATH . '/apps/cost-manager/cost-workbook-migration.sql');
    if ($sql === false) throw new RuntimeException('Cost Workbook migration file is unavailable.');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
        $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
        if ($statement !== '') $pdo->exec($statement);
    }
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
