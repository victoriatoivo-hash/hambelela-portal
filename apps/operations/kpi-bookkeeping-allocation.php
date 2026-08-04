<?php

declare(strict_types=1);
require_once __DIR__ . '/operations.php';

header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST required.');
    if (current_role_key() !== 'owner_admin') { http_response_code(403); throw new RuntimeException('Only the Owner/Admin may confirm Bookkeeping allocations.'); }
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals((string)($_SESSION['kpi_presence_csrf_token'] ?? ''), $csrf)) { http_response_code(403); throw new RuntimeException('Your session token is invalid.'); }
    if (!ops_table_exists('bookkeeping_order_allocations') || !ops_table_exists('bookkeeping_order_allocation_audit')) throw new RuntimeException('Run operations-bookkeeping-kpi-migration.sql before confirming allocations.');
    $orderId=max(0,(int)($_POST['order_id']??0));$entryId=max(0,(int)($_POST['bookkeeping_entry_id']??0));$amountCents=(int)round((float)($_POST['cash_amount']??0)*100);$note=trim((string)($_POST['note']??''));
    if(!$orderId||!$entryId||$amountCents<=0||$note==='')throw new RuntimeException('Order, Bookkeeping entry, cash amount and supporting note are required.');
    $order=ops_rows("SELECT o.id,o.order_number,p.amount_cents FROM ops_orders o JOIN order_payment_allocations p ON p.order_id=o.id AND p.payment_method='cash' WHERE o.id=? AND o.payment_status IN ('paid','partial') LIMIT 1",[$orderId])[0]??null;
    $entry=ops_rows("SELECT id,cash_in,status,deleted_at FROM ops_cash_book_entries WHERE id=? LIMIT 1",[$entryId])[0]??null;
    if(!$order)throw new RuntimeException('The order has no authoritative received cash component.');
    if(!$entry||!empty($entry['deleted_at'])||(string)$entry['status']!=='active')throw new RuntimeException('Choose an active Bookkeeping cash-in entry.');
    if($amountCents>(int)round((float)$entry['cash_in']*100))throw new RuntimeException('The allocation exceeds the Bookkeeping cash-in amount.');
    $allocated=(int)(ops_rows("SELECT COALESCE(SUM(cash_amount_allocated_cents),0) total FROM bookkeeping_order_allocations WHERE bookkeeping_entry_id=? AND review_status='confirmed' AND order_id<>?",[$entryId,$orderId])[0]['total']??0);
    if($allocated+$amountCents>(int)round((float)$entry['cash_in']*100))throw new RuntimeException('Confirmed allocations would exceed this Bookkeeping entry.');
    $actor=ops_current_employee_id();$db=db();$db->beginTransaction();
    try{
        $previous=ops_rows('SELECT * FROM bookkeeping_order_allocations WHERE bookkeeping_entry_id=? AND order_id=? LIMIT 1',[$entryId,$orderId])[0]??null;
        $stmt=$db->prepare("INSERT INTO bookkeeping_order_allocations (bookkeeping_entry_id,order_id,cash_amount_allocated_cents,created_by,review_status,reviewed_by,reviewed_at,review_note) VALUES (?,?,?,?,'confirmed',?,CURRENT_TIMESTAMP,?) ON DUPLICATE KEY UPDATE cash_amount_allocated_cents=VALUES(cash_amount_allocated_cents),review_status='confirmed',reviewed_by=VALUES(reviewed_by),reviewed_at=CURRENT_TIMESTAMP,review_note=VALUES(review_note)");
        $stmt->execute([$entryId,$orderId,$amountCents,$actor,$actor,$note]);$allocationId=(int)($previous['allocation_id']??$db->lastInsertId());
        $new=['bookkeeping_entry_id'=>$entryId,'order_id'=>$orderId,'cash_amount_allocated_cents'=>$amountCents,'review_status'=>'confirmed'];
        $db->prepare('INSERT INTO bookkeeping_order_allocation_audit (allocation_id,bookkeeping_entry_id,order_id,previous_value_json,new_value_json,action,actor_id,supporting_note) VALUES (?,?,?,?,?,?,?,?)')->execute([$allocationId,$entryId,$orderId,$previous?json_encode($previous,JSON_UNESCAPED_SLASHES):null,json_encode($new,JSON_UNESCAPED_SLASHES),$previous?'updated':'confirmed',$actor,$note]);
        if(ops_table_exists('hambelela_cashbook_log'))$db->prepare("INSERT INTO hambelela_cashbook_log (entry_id,action,field,old_value,new_value,description,user_id,user_name,created_at) SELECT ?,'historical_allocation','related_order_id',?,?,?,e.id,e.full_name,CURRENT_TIMESTAMP FROM ops_employees e WHERE e.id=?")->execute([$entryId,$previous?json_encode($previous,JSON_UNESCAPED_SLASHES):null,(string)$orderId,'Order '.$order['order_number'].' allocation confirmed. '.$note,$actor]);
        $db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    echo json_encode(['ok'=>true,'message'=>'Bookkeeping allocation confirmed.'],JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if(http_response_code()<400)http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>$error->getMessage()],JSON_UNESCAPED_SLASHES);
}
