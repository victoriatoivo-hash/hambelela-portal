<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/accounts-output-vat.php';
output_vat_require_owner(); output_vat_schema_ready();

function output_vat_json(array $payload,int $status=200): void { http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($payload,JSON_UNESCAPED_SLASHES);exit; }

try {
    $action=(string)($_REQUEST['action']??'list'); $month=output_vat_month((string)($_REQUEST['month']??date('Y-m')));
    if($action==='export'){
        $data=output_vat_payload($month,(string)($_GET['search']??''),(string)($_GET['status']??''),(string)($_GET['treatment']??''));
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="output-vat-'.$month.'.csv"');
        $out=fopen('php://output','w');fputcsv($out,['Output VAT period',$month]);foreach($data['summary'] as $key=>$value)fputcsv($out,[$key,$value]);fputcsv($out,[]);fputcsv($out,['Date','Order ID','Status','Included','Total incl VAT','Shipping','Discount','Refund','Standard-rated incl','Zero-rated','Exempt/non-taxable','Woo VAT','Expected VAT','Net excl VAT']);
        foreach($data['orders'] as $row)fputcsv($out,[$row['order_date'],$row['order_number'],$row['order_status'],$row['included']?'Yes':'No',$row['total_sales_incl_vat'],$row['shipping'],$row['discount_amount'],$row['refund_amount'],$row['standard_rated_incl'],$row['zero_rated_sales'],$row['exempt_sales'],$row['woo_vat'],$row['expected_vat'],$row['net_sales_excl_vat']]);fclose($out);exit;
    }
    if($_SERVER['REQUEST_METHOD']==='POST'){
        output_vat_verify_csrf((string)($_POST['csrf']??''));
        if($action==='sync'){output_vat_json(['ok'=>true,'data'=>output_vat_sync($month)]);}
        if($action==='adjust'){
            $amount=round((float)($_POST['amount']??0),2);$reason=trim((string)($_POST['reason']??''));$note=trim((string)($_POST['note']??''));if($reason==='')throw new RuntimeException('Enter the reason for this adjustment.');
            $before=output_vat_period($month);$stmt=db()->prepare("INSERT INTO accounts_output_vat_periods(month_key,adjustment_amount,adjustment_reason,adjustment_note,reconciliation_status) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE adjustment_amount=VALUES(adjustment_amount),adjustment_reason=VALUES(adjustment_reason),adjustment_note=VALUES(adjustment_note),reconciliation_status=VALUES(reconciliation_status)");$stmt->execute([$month,$amount,$reason,$note,'adjusted']);$after=output_vat_period($month);output_vat_audit($month,'adjustment',$before,$after);output_vat_json(['ok'=>true,'message'=>'Output VAT adjustment recorded.','data'=>output_vat_payload($month)]);
        }
        if($action==='complete'){
            $payload=output_vat_payload($month);if(empty($payload['period']['synced_at']))throw new RuntimeException('Sync the WooCommerce source before completing this period.');
            $period=output_vat_period($month);if(!empty($period['completed_at']))output_vat_json(['ok'=>true,'message'=>'This period was already completed.','data'=>$payload]);
            $snapshot=json_encode($payload,JSON_UNESCAPED_SLASHES);$hash=(string)($period['source_hash']??hash('sha256',$snapshot));$user=current_user();$stmt=db()->prepare("UPDATE accounts_output_vat_periods SET reconciliation_status=?,snapshot_hash=?,snapshot_json=?,completed_at=NOW(),completed_by=?,completed_by_name=? WHERE month_key=?");$stmt->execute([$payload['period']['status'],$hash,$snapshot,(int)($user['id']??0),(string)($user['name']??'Owner'),$month]);output_vat_audit($month,'period_completed',null,['snapshot_hash'=>$hash]);output_vat_json(['ok'=>true,'message'=>'Output VAT period marked complete.','data'=>output_vat_payload($month)]);
        }
        throw new RuntimeException('Unsupported Output VAT action.');
    }
    $period=output_vat_period($month);$stale=empty($period['source_synced_at'])||(time()-strtotime((string)$period['source_synced_at'])>(($month===date('Y-m'))?300:3600));
    if($stale){try{$data=output_vat_sync($month);}catch(Throwable $syncError){$stmt=db()->prepare("INSERT INTO accounts_output_vat_periods(month_key,sync_error) VALUES(?,?) ON DUPLICATE KEY UPDATE sync_error=VALUES(sync_error)");$stmt->execute([$month,$syncError->getMessage()]);$data=output_vat_payload($month);$data['sync_warning']=$syncError->getMessage();}}
    else $data=output_vat_payload($month,(string)($_GET['search']??''),(string)($_GET['status']??''),(string)($_GET['treatment']??''));
    output_vat_json(['ok'=>true,'data'=>$data]);
}catch(Throwable $e){output_vat_json(['ok'=>false,'message'=>$e->getMessage()],400);}
