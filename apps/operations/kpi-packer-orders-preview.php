<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/epi/PackerOrdersPreview.php';
use Hambelela\EPI\PackerOrdersPreview;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function pop_reply(array $body,int $code=200): void {http_response_code($code);echo json_encode($body,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES);exit;}
function pop_json($s): array {return json_decode((string)$s,true)?:[];}
try {
    require_login();
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') pop_reply(['ok'=>false,'message'=>'Read-only preview'],405);
    $id=(int)($_GET['id']??ops_current_employee_id());$owner=current_role_key()==='owner_admin';
    if (!$owner && $id!==ops_current_employee_id()) pop_reply(['ok'=>false,'message'=>'You may view only your own performance'],403);
    $employee=ops_rows('SELECT e.id,e.full_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?',[$id])[0]??null;
    if (!$employee || strpos($employee['role_key'],'packer')===false) pop_reply(['ok'=>false,'message'=>'Packer profile required'],404);
    $now=new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek'));
    $from=(string)($_GET['from']??$now->format('Y-m-01'));$to=(string)($_GET['to']??$now->format('Y-m-d'));
    foreach ([$from,$to] as $day) { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$day); if(!$d||$d->format('Y-m-d')!==$day)throw new RuntimeException('Choose valid reporting dates'); }
    if($from>$to || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366)throw new RuntimeException('Choose a date range of no more than one year');
    $from.=' 00:00:00';$to.=' 23:59:59';$asOf=min($to,$now->format('Y-m-d H:i:s'));
    $names=[];foreach(ops_rows('SELECT id,full_name FROM ops_employees')as$p)$names[(int)$p['id']]=$p['full_name'];
    $closures=[];
    if(ops_table_exists('kpi_holidays'))foreach(ops_rows('SELECT holiday_date FROM kpi_holidays WHERE active=1')as$r)$closures[]=$r['holiday_date'];
    if(ops_table_exists('epi_employee_business_calendar'))foreach(ops_rows('SELECT business_date FROM epi_employee_business_calendar WHERE is_working_day=0')as$r)$closures[]=$r['business_date'];
    $engine=new PackerOrdersPreview($closures);
    $deleted=ops_column_exists('ops_orders','deleted_at')?' WHERE o.deleted_at IS NULL':'';
    $mode=ops_column_exists('ops_orders','fulfilment_mode')?"COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type)":'o.order_type';
    $paid=ops_column_exists('ops_orders','portal_paid_confirmed')?'o.portal_paid_confirmed':"CASE WHEN o.payment_status='paid' THEN 1 ELSE 0 END";
    $created=ops_order_display_datetime_expr('o');$orders=[];
    foreach(ops_rows("SELECT o.id,o.order_number,{$mode} mode,o.customer_contact contact,o.status,o.assigned_packer_id assigned_id,o.payment_method,{$paid} paid,{$created} display_date FROM ops_orders o{$deleted}")as$r)$orders[(int)$r['id']]=$r;
    $events=[];$owners=[];$exceptions=[];$hasStatus=[];
    // This source explicitly writes UTC_TIMESTAMP; convert once to Namibia.
    if(ops_table_exists('kpi_status_events')){
        $q=db()->query("SELECT id,record_id,old_status,new_status,changed_by,changed_at FROM kpi_status_events WHERE module='order' ORDER BY changed_at,id");
        while($s=$q->fetch(PDO::FETCH_ASSOC)){
            $oid=(int)$s['record_id'];if(!isset($orders[$oid]))continue;
            $after=PackerOrdersPreview::status((string)$s['new_status']);$before=PackerOrdersPreview::status((string)$s['old_status']);
            $kind=$after==='new'?'new':($before==='new'&&$after==='progress'?'packed':null);if(!$kind)continue;
            $at=(new DateTimeImmutable($s['changed_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('Y-m-d H:i:s');
            $events[$oid][]=['kind'=>$kind,'at'=>$at,'actor'=>(int)$s['changed_by'],'source'=>'kpi_status_events:'.$s['id']];if($kind==='packed')$hasStatus[$oid]=true;
        }$q->closeCursor();
    }
    // Durable snapshots contain local metadata.occurred_at and exact before/after values.
    // Never infer a first Paid timestamp from the current tick or from payment method.
    if(ops_table_exists('epi_v2_outbox')){
        $q=db()->query("SELECT id,entity_id,action,payload_json FROM epi_v2_outbox WHERE entity_type='order' ORDER BY id");
        while($s=$q->fetch(PDO::FETCH_ASSOC)){
            $oid=(int)$s['entity_id'];if(!isset($orders[$oid]))continue;
            $p=pop_json($s['payload_json']);$m=$p['metadata']??[];$r=$p['record']??[];$at=$m['occurred_at']??null;if(!$at)continue;
            $event=['at'=>$at,'actor'=>(int)($m['actor_employee_id']??$m['employee_id']??0),'source'=>'epi_v2_outbox:'.$s['id']];
            if($s['action']==='order_created' && PackerOrdersPreview::status((string)($r['status']??''))==='new'){
                $start=(string)($m['original_created_at']??$at);
                if($start<=$at)$events[$oid][]=array_replace($event,['kind'=>'new','at'=>$start]);
                if((int)($r['portal_paid_confirmed']??0)===1)$events[$oid][]=$event+['kind'=>'paid'];
            }
            $field=(string)($m['field']??'');$before=(string)($m['old_value']??$m['previous_status']??$m['old_status']??'');$after=(string)($m['new_value']??$m['new_status']??'');
            if(in_array($field,['portal_paid_confirmation','portal_paid_confirmed','payment_status'],true) && in_array(strtolower($after),['paid','1','yes'],true) && !in_array(strtolower($before),['paid','1','yes'],true))$events[$oid][]=$event+['kind'=>'paid'];
            if(in_array($field,['portal_paid_confirmation','portal_paid_confirmed','payment_status'],true) && in_array(strtolower($before),['paid','1','yes'],true) && in_array(strtolower($after),['unpaid','0','no'],true))$events[$oid][]=$event+['kind'=>'unpaid'];
            if(($field==='status'||in_array($s['action'],['status_changed','order_status_changed','bulk_status_updated'],true))){
                if(PackerOrdersPreview::status($after)==='new')$events[$oid][]=$event+['kind'=>'new'];
                if(empty($hasStatus[$oid])&&PackerOrdersPreview::status($before)==='new'&&PackerOrdersPreview::status($after)==='progress')$events[$oid][]=$event+['kind'=>'packed'];
            }
        }$q->closeCursor();
    }
    if(ops_table_exists('epi_v2_ownership_periods'))foreach(ops_rows("SELECT object_reference,employee_id,effective_from,effective_to FROM epi_v2_ownership_periods WHERE module='Packing' AND object_reference LIKE 'ORDER-%'")as$r)$owners[(int)substr($r['object_reference'],6)][]=$r;
    if(ops_table_exists('epi_v2_exceptions'))foreach(ops_rows("SELECT object_reference,employee_id,effective_from,effective_to,reason FROM epi_v2_exceptions WHERE approved_by IS NOT NULL AND approved_at IS NOT NULL AND (module='Packing' OR module IS NULL) AND (kind='absence' OR (kind='obligation_excusal' AND object_reference LIKE 'ORDER-%'))")as$r){
        if(empty($r['object_reference']))$exceptions[0][]=$r;elseif(strpos($r['object_reference'],'ORDER-')===0)$exceptions[(int)substr($r['object_reference'],6)][]=$r;
    }
    $rows=[];$team=0;
    foreach($orders as$oid=>$order){
        $r=$engine->evaluate($order,$events[$oid]??[],$owners[$oid]??[],array_merge($exceptions[0]??[],$exceptions[$oid]??[]),$asOf);
        $inPacked=$r['packed_at']!==null&&$r['packed_at']>=$from&&$r['packed_at']<=$to;
        $inDue=$r['due_at']!==null&&$r['due_at']>=$from&&$r['due_at']<=$to;
        $inOpen=$r['packed_at']===null&&$r['eligible_at']!==null&&$r['eligible_at']<=$asOf;
        $inMissing=$r['display_date']>=$from&&$r['display_date']<=$to;
        if(!$inPacked&&!$inDue&&!$inOpen&&!$inMissing)continue;
        if(!$r['responsible_id']&&$r['result']==='Overdue')$team++;
        if((int)$r['responsible_id']!==$id && (int)$r['packed_by']!==$id && (int)$r['assigned_id']!==$id)continue;
        $r['responsible_name']=$names[$r['responsible_id']]??'Not proven';$r['packed_by_name']=$names[$r['packed_by']]??'Not recorded';
        $r['paid']=$r['paid']===null?null:(bool)$r['paid'];$r['in_packed_period']=$inPacked;$r['in_deadline_period']=$inDue;
        unset($r['contact']);$rows[]=$r;
    }
    usort($rows,static fn($a,$b)=>strcmp($b['packed_at']??$b['due_at']??$b['display_date'],$a['packed_at']??$a['due_at']??$a['display_date']));
    $modeFilter=(string)($_GET['mode']??'');$payment=(string)($_GET['payment']??'');$paidFilter=(string)($_GET['paid']??'');
    $payments=array_values(array_unique(array_column($rows,'payment_method')));sort($payments);
    $rows=array_values(array_filter($rows,static fn($r)=>($modeFilter===''||$r['mode']===$modeFilter)&&($payment===''||$r['payment_method']===$payment)&&($paidFilter===''||($r['paid']!==null && $r['paid']===($paidFilter==='1')))));
    pop_reply(['ok'=>true,'test_mode'=>true,'version'=>PackerOrdersPreview::VERSION,'employee'=>$employee,'from'=>$from,'to'=>$to,'as_of'=>$asOf,'rows'=>$rows,'payments'=>$payments,'summary'=>PackerOrdersPreview::summary($rows,$id,$from,$to),'team_overdue_unattributed'=>$owner?$team:null,'scoring_note'=>'Test only. Deadline-date cohort; pending, missing responsibility and approved exclusions are not failures. No official score, payroll, historical evidence or order was changed.','calendar_note'=>'Mon–Fri 08:00–17:00; Sat 09:00–13:00; Sundays, statutory Namibian holidays and saved calendar closures excluded. Special holiday declarations must be maintained in the existing calendar.']);
} catch(Throwable $e) {error_log('Packer Orders preview: '.$e->getMessage());pop_reply(['ok'=>false,'message'=>$e instanceof PDOException?'Orders evidence could not be read. No records were changed.':$e->getMessage()],422);}
