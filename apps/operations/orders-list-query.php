<?php
declare(strict_types=1);

// Shared by the paged list and explicit all-filtered selection. No writes.
function ops_list_compact_sql(string $expression): string
{
    foreach ([' ', '+', '-', '(', ')', '.', "\t", "\r", "\n"] as $character) {
        $expression = 'REPLACE(' . $expression . ', ' . "'" . $character . "', '')";
    }
    return $expression;
}

function ops_list_like(string $value): string
{
    return '%' . strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

function ops_list_reference(string $value): string
{
    $value = preg_replace('/^\s*(?:order\s+|(?:WEB|INV)[-_\s]+)/i', '', $value) ?? $value;
    return preg_replace('/\s+/', '', ltrim(trim($value), '#+ ')) ?? '';
}

function ops_list_search(string $search, string $nameExpression): array
{
    $search = trim(preg_replace('/\s+/u', ' ', substr($search, 0, 160)) ?? '');
    if ($search === '') return ['', []];
    $reference = ops_list_reference($search);
    $refSql = "CASE WHEN LOWER(o.order_number) LIKE 'order %' THEN SUBSTRING(o.order_number, 7) WHEN UPPER(LEFT(o.order_number,4)) IN ('WEB-','WEB_','WEB ','INV-','INV_','INV ') THEN SUBSTRING(o.order_number,5) ELSE o.order_number END";
    $refSql = "REPLACE(TRIM(LEADING '#' FROM TRIM(LEADING '+' FROM TRIM(LEADING '#' FROM TRIM({$refSql})))), ' ', '')";
    $parts = ["LOWER({$refSql}) = LOWER(?)"];
    $params = [$reference];
    // One-character queries are exact references only. LIKE wildcards are literal.
    if (strlen($search) >= 2) {
        $nameParts = [];
        foreach (preg_split('/\s+/', $search) as $word) {
            $nameParts[] = "LOWER({$nameExpression}) LIKE LOWER(?) ESCAPE '!'";
            $params[] = ops_list_like($word);
        }
        $parts[] = '(' . implode(' AND ', $nameParts) . ')';
        $phone = preg_replace('/[\s+().-]/', '', $search) ?? '';
        if ($phone !== '' && ctype_digit($phone)) {
            $parts[] = ops_list_compact_sql("COALESCE(o.customer_contact,'')") . " LIKE ? ESCAPE '!'";
            $params[] = ops_list_like($phone);
        }
        $parts[] = "LOWER({$refSql}) LIKE LOWER(?) ESCAPE '!'";
        $params[] = ops_list_like($reference);
    }
    return ['(' . implode(' OR ', $parts) . ')', $params];
}

function ops_list_filters(array $input, string $dateExpression, string $paidExpression, int $employeeId): array
{
    $parts = []; $params = [];
    foreach (['status'=>'o.status', 'mode'=>"COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type)", 'paid'=>$paidExpression] as $key=>$column) {
        if (trim((string)($input[$key] ?? '')) !== '') {
            $parts[] = "LOWER(REPLACE({$column},' ','_')) = LOWER(REPLACE(?,' ','_'))";
            $params[] = substr((string)$input[$key], 0, 80);
        }
    }
    $person = (string)($input['person'] ?? '');
    if ($person === '__me__') { $parts[] = 'o.assigned_packer_id = ?'; $params[] = $employeeId; }
    elseif ($person === 'Unassigned') $parts[] = '(o.assigned_packer_id IS NULL OR o.assigned_packer_id = 0)';
    elseif ($person !== '') { $parts[] = 'LOWER(e.full_name) = LOWER(?)'; $params[] = substr($person, 0, 190); }
    $payment = trim((string)($input['payment'] ?? ''));
    if ($payment !== '') {
        $method = ops_normalize_payment_code($payment);
        $parts[] = '(LOWER(o.payment_method) = LOWER(?) OR EXISTS (SELECT 1 FROM order_payment_allocations fp WHERE fp.order_id=o.id AND fp.payment_method=?))';
        $params[] = $payment; $params[] = $method;
    }
    foreach (['minAmount'=>'>=', 'maxAmount'=>'<='] as $key=>$operator) {
        if (isset($input[$key]) && is_numeric($input[$key])) { $parts[] = "o.total_amount {$operator} ?"; $params[] = $input[$key]; }
    }
    foreach (['createdAfter'=>'>=', 'createdBefore'=>'<'] as $key=>$operator) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($input[$key] ?? ''))) {
            $parts[] = "{$dateExpression} {$operator} ?";
            $params[] = $key === 'createdBefore' ? date('Y-m-d', strtotime($input[$key].' +1 day')).' 00:00:00' : $input[$key].' 00:00:00';
        }
    }
    return [$parts, $params];
}

function ops_list_money(array $order, array $payments): array
{
    $total = isset($order['total_amount']) && is_numeric($order['total_amount']) ? (int)round((float)$order['total_amount'] * 100) : null;
    $paid = 0; $reliable = $total !== null && $total >= 0 && count($payments) > 0;
    foreach ($payments as $payment) {
        $amount = $payment['amount_cents'] ?? null;
        if (!is_numeric($amount) || (int)$amount < 0 || !in_array($payment['source'] ?? '', ['pos','order_list','woocommerce'], true) || empty($payment['source_version'] ?? $payment['version'] ?? '')) $reliable = false;
        $paid += (int)$amount;
    }
    $status = $order['financial_payment_status'] ?? $order['payment_status'] ?? '';
    if ($total === null || $paid > $total || ($status === 'paid' && $paid !== $total) || ($status === 'partial' && ($paid <= 0 || $paid >= $total)) || !in_array($status, ['paid','partial'], true) || in_array($order['status'] ?? '', ['cancelled','canceled','refunded'], true)) $reliable = false;
    return ['total_cents'=>$total, 'paid_cents'=>$reliable ? $paid : null, 'outstanding_cents'=>$reliable ? $total-$paid : null, 'payment_verified'=>$reliable];
}
