<?php
declare(strict_types=1);

function ops_pos_gateway_id(string $code): string
{
    return ['card_swipe'=>'swipe','fnb_ewallet'=>'fnbwlt','easywallet'=>'easywlt','blue_wallet'=>'bluewlt','netbank_wallet'=>'netbank'][$code] ?? $code;
}

// The POS updates the Woo gateway; an earlier portal allocation may still name the old tender.
function ops_pos_single_tender(array $order): ?array
{
    $meta = array_column((array)($order['meta_data'] ?? []), 'value', 'key');
    if (empty($meta['_hpos_sale']) && ($meta['_hpos_payment_source'] ?? '') !== 'order_list') return null;
    $method = ops_normalize_payment_code((string)($order['payment_method'] ?? ''));
    if ($method === '') $method = ops_normalize_payment_code((string)($order['payment_method_title'] ?? ''));
    if ($method === '' || stripos((string)($order['payment_method_title'] ?? ''), 'split') !== false) return null;
    $saved = [];
    foreach (['_hpos_split','_hpos_payment_allocations'] as $key) {
        $v = $meta[$key] ?? [];
        if (is_string($v)) $v = $v === '' ? [] : json_decode($v, true);
        if (!is_array($v) || count($v) > 1 || ($key === '_hpos_split' && $v)) return null;
        if ($key === '_hpos_payment_allocations' && $v) {
            $saved = reset($v);
            if (!is_array($saved) || !isset($saved['amount_cents']) || filter_var($saved['amount_cents'], FILTER_VALIDATE_INT) === false || $saved['amount_cents'] < 0) return null;
        }
    }
    $paid = !empty($order['date_paid']) || !empty($order['date_paid_gmt']);
    return ['method'=>$method,'amount_cents'=>$saved ? (int)$saved['amount_cents'] : ($paid ? (int)round((float)($order['total'] ?? 0)*100) : 0),
        'transaction_reference'=>(string)($saved['transaction_reference'] ?? ''),'_pos_method_only'=>true,'_origin_version'=>(string)($meta['_hpos_payment_version'] ?? ''),
        '_dated_receipt'=>$paid && !$saved];
}

function ops_pos_method_updates(array $incoming, array $existing, string $source, string $version, string $localVersion): ?array
{
    if ($source === 'delivery' || count($existing) > 1) return null;
    $remoteTime = strtotime(substr($version,0,19).' UTC');
    $localTime = strtotime(substr($localVersion,0,19).' UTC');
    if ($localVersion !== '' && (!$remoteTime || ($localTime && $remoteTime < $localTime))) return null;
    if ($source === 'order_list' && $incoming['_origin_version'] !== $localVersion) return null;
    if ($existing) {
        $row = $existing[0];
        if ($row['method'] !== $incoming['method'] || !$incoming['_dated_receipt']) $incoming['amount_cents'] = (int)$row['amount_cents'];
        if ($row['method'] === $incoming['method'] && (int)$row['amount_cents'] === $incoming['amount_cents']) return null;
        $incoming['transaction_reference'] = (string)($row['transaction_reference'] ?? '');
    }
    unset($incoming['_origin_version'], $incoming['_pos_method_only'], $incoming['_dated_receipt']);
    return [$incoming];
}
