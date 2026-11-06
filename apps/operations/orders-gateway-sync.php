<?php
declare(strict_types=1);

/** The gateway ID is authoritative; a card label is not a shop swipe. */
function ops_wc_dpo_state(array $order): ?array
{
    $method = strtolower(trim((string) ($order['payment_method'] ?? '')));
    if (!in_array($method, ['woocommerce_dpo','dpo','dpo_pay','dpo_paygate','paygate','paygate_payweb3'], true)) return null;
    $meta = array_column((array)($order['meta_data'] ?? []), 'value', 'key');
    foreach (['_hpos_split','_hpos_payment_allocations'] as $key) {
        $parts = $meta[$key] ?? [];
        if (is_string($parts)) $parts = json_decode($parts, true);
        if (is_array($parts) && count($parts) > 1) return null; // Mixed tenders need allocation reconciliation.
    }
    $status = (string) ($order['status'] ?? 'pending');
    $paidDate = (string) (($order['date_paid_gmt'] ?? '') ?: ($order['date_paid'] ?? ''));
    $paid = in_array($status, ['processing','completed'], true) && $paidDate !== '';
    $messages = ['failed'=>'Failed payment', 'pending'=>'Pending payment', 'on-hold'=>'Pending payment', 'cancelled'=>'Payment cancelled', 'refunded'=>'Payment refunded'];
    $message = $paid ? 'Payment confirmed' : ($messages[$status] ?? 'Pending payment confirmation');
    return ['paid'=>$paid, 'status'=>$status, 'note'=>'[DPO payment] '.$message.'.',
        'financial_status'=>$status === 'refunded' ? 'refunded' : ($paid ? 'paid' : 'unpaid'),
        'amount_cents'=>$paid ? max(0, (int)round((float)($order['total'] ?? 0)*100)) : 0,
        'transaction_reference'=>(string)($order['transaction_id'] ?? ''),
        'modified'=>(string)(($order['date_modified_gmt'] ?? '') ?: ($order['date_modified'] ?? ''))];
}

function ops_dpo_sync_note(string $notes, string $message): string
{
    $notes = preg_replace('/^\[DPO payment\][^\r\n]*(?:\r?\n|$)/m', '', $notes) ?? $notes;
    return trim($notes).(trim($notes) !== '' ? "\n" : '').$message;
}

/** Called inside the same transaction as the website order import. */
function ops_sync_dpo_payment(int $orderId, array $order): void
{
    $state = ops_wc_dpo_state($order);
    if ($state === null) return;
    if (!db()->inTransaction()) throw new LogicException('DPO synchronization requires an order transaction.');
    $current = ops_row('SELECT payment_source, payment_version, portal_paid_confirmed, notes FROM ops_orders WHERE id = ? FOR UPDATE', [$orderId]);
    if (!$current) throw new RuntimeException('Order not found during DPO synchronization.');
    // Driver receipts have their own reconciled ledger. Never replace collected cash.
    if (($current['payment_source'] ?? '') === 'delivery') return;
    $version = 'dpo-v1:'.$state['modified'].':'.substr(hash('sha256', json_encode($state)), 0, 24);
    $oldVersion = (string)($current['payment_version'] ?? '');
    if (strpos($oldVersion, 'dpo-v1:') === 0 && substr($oldVersion, 7, 19) > substr($state['modified'], 0, 19)) return;
    $notes = ops_dpo_sync_note((string)($current['notes'] ?? ''), $state['note']);
    if ($oldVersion === $version) {
        // Import refreshes the base shipping/customer note on each poll.
        db()->prepare('UPDATE ops_orders SET payment_method = ?, notes = ?, portal_paid_confirmed = ? WHERE id = ?')
            ->execute(['DPO payment', $notes, $state['paid'] ? 1 : 0, $orderId]);
        return;
    }
    $previous = ops_rows('SELECT payment_method, amount_cents, transaction_reference, source, source_version FROM order_payment_allocations WHERE order_id = ? ORDER BY id FOR UPDATE', [$orderId]);
    $allocations = $state['amount_cents'] > 0 ? [['method'=>'dpo','amount_cents'=>$state['amount_cents'],'transaction_reference'=>$state['transaction_reference']]] : [];
    db()->prepare('DELETE FROM order_payment_allocations WHERE order_id = ?')->execute([$orderId]);
    if ($allocations) {
        db()->prepare('INSERT INTO order_payment_allocations (order_id, payment_method, amount_cents, transaction_reference, source, source_version) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$orderId, 'dpo', $state['amount_cents'], $state['transaction_reference'], 'woocommerce', $version]);
    }
    db()->prepare('UPDATE ops_orders SET payment_method = ?, payment_status = ?, payment_source = ?, payment_version = ?, payment_updated_at = CURRENT_TIMESTAMP, payment_updated_by_employee_id = NULL, portal_paid_confirmed = ?, portal_paid_source = ?, portal_paid_decided_at = UTC_TIMESTAMP(), portal_paid_decided_by_employee_id = NULL, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute(['DPO payment', $state['financial_status'], 'woocommerce', $version, $state['paid'] ? 1 : 0, 'auto_dpo_gateway', $notes, $orderId]);
    db()->prepare('INSERT INTO order_payment_allocation_audit (order_id, previous_allocations_json, new_allocations_json, changed_by_employee_id, source) VALUES (?, ?, ?, ?, ?)')
        ->execute([$orderId, json_encode($previous), json_encode($allocations), null, 'woocommerce']);
    ops_activity_log('dpo_payment_sync', 'order', $orderId, ['source'=>'woocommerce','status'=>$state['status'],'paid'=>$state['paid'],'payment_method'=>'DPO payment']);
}
