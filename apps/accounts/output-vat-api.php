<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/accounts-output-vat.php';
output_vat_require_owner(); output_vat_schema_ready();

function output_vat_json(array $payload,int $status=200): void { http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($payload,JSON_UNESCAPED_SLASHES);exit; }

try {
    $action=(string)($_REQUEST['action']??'list'); $month=output_vat_month((string)($_REQUEST['month']??date('Y-m')));
    if($action==='source_report'){
        $metrics=[];$report=output_vat_fetch_source_report($month,$metrics);$rates=output_vat_fetch_tax_rate_catalog($metrics);
        output_vat_json(['ok'=>true,'data'=>['month'=>$month,'report'=>$report,'tax_rates'=>array_values($rates),'timing'=>$metrics]]);
    }
    if($action==='export'){
        if (!accounts_can('output_vat.export')) throw new RuntimeException('You cannot export Output VAT.');
        $data=output_vat_payload($month,(string)($_GET['search']??''),(string)($_GET['status']??''),(string)($_GET['treatment']??''));
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="output-vat-'.$month.'.csv"');
        $out=fopen('php://output','w');fputcsv($out,['Output VAT period',$month]);fputcsv($out,['Qualifying orders',$data['summary']['orders']??0]);fputcsv($out,['Gross website sales incl VAT and shipping',$data['summary']['gross_sales']??0]);fputcsv($out,['Shipping',$data['summary']['shipping']??0]);fputcsv($out,['Taxable sales incl VAT, excluding shipping',$data['summary']['total_sales']??0]);fputcsv($out,['Net sales excluding VAT (Woo report)',$data['summary']['net_sales_excl_vat']??0]);
        fputcsv($out,['WooCommerce Reported VAT',$data['summary']['woo_vat']??0]);fputcsv($out,['VAT if all taxable at configured rate',$data['summary']['expected_vat']??0]);fputcsv($out,['Reconciliation difference',$data['summary']['difference']??0]);fputcsv($out,['Explained by tax treatment',$data['summary']['explained_difference']??0]);fputcsv($out,['Unexplained difference',$data['summary']['unexplained_difference']??0]);fputcsv($out,['Owner adjustment',$data['period']['adjustment']??0]);fputcsv($out,['Final adjusted Output VAT',$data['period']['final_output_vat']??0]);fputcsv($out,[]);fputcsv($out,['Date','Order ID','Status','Included','Taxable sales incl VAT','Shipping','Discount','Refund','Standard-rated incl','Zero-rated','Exempt/review','Woo order tax','VAT if all taxable','Net excl VAT']);
        foreach($data['orders'] as $row)fputcsv($out,[$row['order_date'],$row['order_number'],$row['order_status'],$row['included']?'Yes':'No',$row['total_sales_incl_vat'],$row['shipping'],$row['discount_amount'],$row['refund_amount'],$row['standard_rated_incl'],$row['zero_rated_sales'],$row['exempt_sales'],$row['woo_vat'],$row['expected_vat'],$row['net_sales_excl_vat']]);fclose($out);exit;
    }
    if($_SERVER['REQUEST_METHOD']==='POST'){
        output_vat_verify_csrf((string)($_POST['csrf']??''));
        if($action==='sync'){if(!accounts_can('output_vat.sync'))throw new RuntimeException('You cannot refresh Output VAT.');output_vat_json(['ok'=>true,'data'=>output_vat_sync_single_flight($month)]);}
        if($action==='adjust'){
            if (!accounts_can('output_vat.adjust')) throw new RuntimeException('You cannot record Output VAT adjustments.');
            $amount=round((float)($_POST['amount']??0),2);$reason=trim((string)($_POST['reason']??''));$note=trim((string)($_POST['note']??''));if($reason==='')throw new RuntimeException('Enter the reason for this adjustment.');
            $before=output_vat_period($month);$stmt=db()->prepare("INSERT INTO accounts_output_vat_periods(month_key,adjustment_amount,adjustment_reason,adjustment_note,reconciliation_status) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE adjustment_amount=VALUES(adjustment_amount),adjustment_reason=VALUES(adjustment_reason),adjustment_note=VALUES(adjustment_note),reconciliation_status=VALUES(reconciliation_status)");$stmt->execute([$month,$amount,$reason,$note,'adjusted']);$after=output_vat_period($month);output_vat_audit($month,'adjustment',$before,$after);output_vat_json(['ok'=>true,'message'=>'Output VAT adjustment recorded.','data'=>output_vat_payload($month)]);
        }
        if($action==='complete'){
            if (!accounts_can('output_vat.complete')) throw new RuntimeException('Owner approval is required to complete an Output VAT period.');
            $payload=output_vat_payload($month);if(empty($payload['period']['synced_at']))throw new RuntimeException('Sync the WooCommerce source before completing this period.');if(($payload['period']['status']??'')==='review_required')throw new RuntimeException('Resolve or document the reconciliation difference before completing this period.');if(!empty($payload['period']['source_mismatch']))throw new RuntimeException('The Woo report order count does not match the synced order rows. Re-sync before completing this period.');
            $period=output_vat_period($month);if(!empty($period['completed_at']))output_vat_json(['ok'=>true,'message'=>'This period was already completed.','data'=>$payload]);
            $snapshot=json_encode($payload,JSON_UNESCAPED_SLASHES);$hash=(string)($period['source_hash']??hash('sha256',$snapshot));$user=current_user();$stmt=db()->prepare("UPDATE accounts_output_vat_periods SET reconciliation_status=?,snapshot_hash=?,snapshot_json=?,completed_at=NOW(),completed_by=?,completed_by_name=? WHERE month_key=?");$stmt->execute([$payload['period']['status'],$hash,$snapshot,(int)($user['id']??0),(string)($user['name']??'Owner'),$month]);output_vat_audit($month,'period_completed',null,['snapshot_hash'=>$hash]);output_vat_json(['ok'=>true,'message'=>'Output VAT period marked complete.','data'=>output_vat_payload($month)]);
        }
        throw new RuntimeException('Unsupported Output VAT action.');
    }
    $data=output_vat_payload($month,(string)($_GET['search']??''),(string)($_GET['status']??''),(string)($_GET['treatment']??''));
    output_vat_json(['ok'=>true,'data'=>$data]);
}catch(Throwable $e){output_vat_json(['ok'=>false,'message'=>$e->getMessage()],400);}
