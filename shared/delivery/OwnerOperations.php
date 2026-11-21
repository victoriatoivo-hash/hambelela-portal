<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/OwnerProofViewer.php';
require_once __DIR__.'/DeliveryIssueState.php';
require_once __DIR__.'/CashHandover.php';

/** Read-only owner projection. No installation, business writes or location tracking. */
final class OwnerOperations
{
    private \PDO $db;
    private OwnerProofViewer $proof;
    public function __construct(\PDO $db,int $owner){$this->db=$db;$this->proof=new OwnerProofViewer($db,$owner);}
    private function rows(string $sql,array $args=[]):array {$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC);}
    private function scalar(string $sql,array $args=[]):int{return (int)array_values($this->rows($sql,$args)[0])[0];}
    private function exists(string $table):bool{return (bool)$this->rows('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table]);}
    public static function parameters(array $p):array
    {
        foreach($p as $v)if(!is_scalar($v))throw new \RuntimeException('Invalid filter.',422);
        $tab=(string)($p['tab']??'all');$sort=(string)($p['sort']??'newest');
        if(!in_array($tab,['all','onroute','delivered','issues','active','reported'],true)||!in_array($sort,['newest','oldest','reference','customer','driver'],true))throw new \RuntimeException('Invalid filter.',422);
        $q=trim((string)($p['q']??''));if(!mb_check_encoding($q,'UTF-8')||mb_strlen($q)>100)throw new \RuntimeException('Search must be at most 100 characters.',422);
        $day=(string)($p['day']??'');$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$day,new \DateTimeZone('Africa/Windhoek'));
        if($day!==''&&(!$d||$d->format('Y-m-d')!==$day))throw new \RuntimeException('Invalid reporting date.',422);
        foreach(['driver','page'] as $n)if(isset($p[$n])&&!preg_match('/^[0-9]{1,9}$/D',(string)$p[$n]))throw new \RuntimeException('Invalid page or driver.',422);
        $ids=(string)($p['ids']??'');if($ids!==''&&!preg_match('/^[1-9][0-9]{0,9}(?:,[1-9][0-9]{0,9}){0,19}$/D',$ids))throw new \RuntimeException('Select at most 20 valid deliveries.',422);
        $size=(string)($p['page_size']??'5');if(!in_array($size,['5','10','20'],true))throw new \RuntimeException('Invalid page size.',422);
        return ['tab'=>$tab,'sort'=>$sort,'q'=>ltrim($q,'#'),'day'=>$day,'driver'=>(int)($p['driver']??0),'page'=>max(1,(int)($p['page']??1)),'page_size'=>(int)$size,'ids'=>$ids];
    }
    /** Latest incident and latest matching resolution, exactly as DeliveryIssueState::latest. */
    private static function issueSql():string
    {
        return "EXISTS(SELECT 1 FROM delivery_events i WHERE i.delivery_id=j.id AND i.event_type='delivery_issue_reported' AND i.id=(SELECT MAX(ii.id) FROM delivery_events ii WHERE ii.delivery_id=j.id AND ii.event_type='delivery_issue_reported') AND COALESCE((SELECT JSON_UNQUOTE(JSON_EXTRACT(r.metadata_json,'$.resume_authorized')) FROM delivery_events r WHERE r.delivery_id=j.id AND r.id>i.id AND r.event_type='dispatch_resolution_recorded' AND JSON_UNQUOTE(JSON_EXTRACT(r.metadata_json,'$.incident_id'))=JSON_UNQUOTE(JSON_EXTRACT(i.metadata_json,'$.incident_id')) ORDER BY r.id DESC LIMIT 1),'false')<>'true')";
    }
    private function query(array $p):array
    {
        $cols=$this->db->query('SHOW COLUMNS FROM ops_employees')->fetchAll(\PDO::FETCH_COLUMN);
        $code=in_array('employee_code',$cols,true)?'e.employee_code':"CAST(e.id AS CHAR)";
        $from=' FROM delivery_jobs j LEFT JOIN ops_orders o ON o.id=j.order_id LEFT JOIN ops_employees e ON e.id=j.driver_employee_id';
        $where=' WHERE j.driver_employee_id IS NOT NULL';$args=[];
        if($p['ids']!==''){$ids=explode(',',$p['ids']);$where.=' AND j.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';array_push($args,...$ids);}
        if($p['driver']){$where.=' AND j.driver_employee_id=?';$args[]=$p['driver'];}
        if($p['day']!==''){$where.=' AND j.scheduled_date=?';$args[]=$p['day'];}
        if($p['q']!==''){
            // Escape SQL LIKE metacharacters; partial user text is literal, not a wildcard expression.
            $like='%'.strtr($p['q'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $where.=" AND (COALESCE(o.order_number,j.public_reference) LIKE ? ESCAPE '!' OR j.customer_name LIKE ? ESCAPE '!' OR e.full_name LIKE ? ESCAPE '!' OR $code LIKE ? ESCAPE '!')";
            array_push($args,$like,$like,$like,$like);
        }
        $issue=self::issueSql();
        $live="j.status IN ('ready','out_for_delivery','failed') AND (j.source<>'hambelela' OR (o.id IS NOT NULL AND o.deleted_at IS NULL AND o.archived_at IS NULL AND LOWER(o.status) NOT IN ('cancelled','canceled','refunded','deleted','trash','failed') AND LOWER(COALESCE(o.payment_status,'')) NOT IN ('refunded','reversed')))";
        $start=(new \DateTimeImmutable('today',new \DateTimeZone('Africa/Windhoek')))->setTimezone(new \DateTimeZone('UTC'));
        $today="EXISTS(SELECT 1 FROM delivery_events t WHERE t.delivery_id=j.id AND t.event_type='delivery_issue_reported' AND t.created_at>='".$start->format('Y-m-d H:i:s')."' AND t.created_at<'".$start->modify('+1 day')->format('Y-m-d H:i:s')."')";
        return [$from,$where,$args,['all'=>'1=1','onroute'=>"j.status='out_for_delivery' AND ($live)",'delivered'=>"j.status IN ('completed','awaiting_reconciliation')",'issues'=>$issue,'active'=>$live,'reported'=>$today],$code];
    }
    private function table(array $p,int $limit=0,int $offset=-1):array
    {
        [$from,$where,$args,$filters]=$this->query($p);
        $counts=$this->rows('SELECT COUNT(*) all_count,'.implode(',',array_map(static fn($k)=>'COALESCE(SUM('.$filters[$k].'),0) '.$k,['onroute','delivered','issues','active','reported'])).$from.$where,$args)[0];
        $counts['all']=$counts['all_count'];unset($counts['all_count']);$counts=array_map('intval',$counts);
        $total=$counts[$p['tab']];$page=min($p['page'],max(1,(int)ceil($total/$p['page_size'])));
        if(!$limit)$limit=$p['page_size'];
        if($limit===2000&&$total>2000)throw new \RuntimeException('Narrow the date, driver or search to export at most 2,000 deliveries.',422);
        if($offset<0)$offset=($page-1)*$p['page_size'];
        $sort=['newest'=>'j.id DESC','oldest'=>'j.id ASC','reference'=>'COALESCE(o.order_number,j.public_reference),j.id DESC','customer'=>'j.customer_name,j.id DESC','driver'=>'e.full_name,j.id DESC'][$p['sort']];
        $jobs=$this->rows('SELECT j.*,o.order_number,e.full_name driver_name'.$from.$where.' AND ('.$filters[$p['tab']].') ORDER BY '.$sort.' LIMIT '.(int)$limit.' OFFSET '.(int)$offset,$args);
        $rows=[];foreach($jobs as $job){
            $d=$this->proof->read((int)$job['id']);
            $state=$d['open']?'Discrepancy':($d['review']&&hash_equals($d['review']['evidence_version'],$d['version'])?'Reviewed':($d['proof']['files']?'Uploaded':($d['proof']['warnings']?'Needs review':'Not recorded')));
            $due=$d['payment']['due'];$collected=0;$receiptIssue=false;
            foreach($d['payment']['collections'] as $r){if(in_array($r['status'],['collected','issue'],true))$collected+=(int)$r['order_goods_cents']+(int)$r['delivery_fee_cents']+(int)$r['partner_cod_cents'];if($r['status']==='issue')$receiptIssue=true;}
            $declaration=$d['declarations']?end($d['declarations']):null;
            $review=$due===null||$receiptIssue||($declaration['review']??false)||($due!==null&&$collected>$due);
            $payment=$review?'Needs reconciliation':($collected>0?'Driver collected '.CashHandover::money($collected).($due>$collected?' · balance due '.CashHandover::money($due-$collected):' · not reconciled'):($due>0?'Due '.CashHandover::money($due):'No amount due'));
            $rows[]=['id'=>(int)$job['id'],'reference'=>$d['reference'],'customer'=>$job['customer_name'],'driver'=>$job['driver_name']??'Unassigned','driver_id'=>(int)$job['driver_employee_id'],'status'=>$job['status'],'payment'=>$payment,'payment_review'=>$review,'proof'=>$state,'proof_url'=>'/apps/operations/driver-delivery-details.php?delivery_id='.$job['id'],'workspace_url'=>'/apps/delivery/workspace.php?delivery='.$job['id'],'can_manage'=>in_array($job['status'],['ready','out_for_delivery','failed'],true)];
        }
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>$p['page_size'],'counts'=>$counts];
    }
    public function read(array $raw):array
    {
        $this->proof->authorize();$p=self::parameters($raw);$now=new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek'));$start=$now->setTime(0,0)->setTimezone(new \DateTimeZone('UTC'));$end=$start->modify('+1 day');
        $cols=$this->db->query('SHOW COLUMNS FROM ops_employees')->fetchAll(\PDO::FETCH_COLUMN);$code=in_array('employee_code',$cols,true)?'e.employee_code':"CONCAT('Employee ',e.id)";
        $drivers=$this->rows("SELECT e.id,e.full_name name,$code code FROM delivery_driver_profiles p JOIN ops_employees e ON e.id=p.employee_id JOIN ops_roles r ON r.id=e.role_id WHERE p.active=1 AND e.status='active' AND r.role_key='delivery_driver' ORDER BY e.full_name,e.id");
        if($p['driver']&&!$this->rows('SELECT id FROM ops_employees WHERE id=?',[$p['driver']]))throw new \RuntimeException('Driver not found.',404);
        [$from,$where,$args,$filters]=$this->query(self::parameters([]));
        $active=$this->rows('SELECT j.driver_employee_id id,COUNT(*) total'.$from.$where.' AND ('.$filters['active'].') GROUP BY j.driver_employee_id');$active=array_column($active,'total','id');
        foreach($drivers as &$driver){$driver['id']=(int)$driver['id'];$driver['active']=(int)($active[$driver['id']]??0);$driver['duty']=null;}unset($driver);
        $issuesToday=$this->scalar("SELECT COUNT(*) FROM delivery_events WHERE event_type='delivery_issue_reported' AND created_at>=? AND created_at<?",[$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
        $open=$this->rows("SELECT j.id,j.public_reference,o.order_number,e.full_name driver_name,i.created_at,i.metadata_json FROM delivery_jobs j LEFT JOIN ops_orders o ON o.id=j.order_id LEFT JOIN ops_employees e ON e.id=j.driver_employee_id JOIN delivery_events i ON i.id=(SELECT MAX(ii.id) FROM delivery_events ii WHERE ii.delivery_id=j.id AND ii.event_type='delivery_issue_reported') WHERE ".self::issueSql()." ORDER BY (JSON_UNQUOTE(JSON_EXTRACT(i.metadata_json,'$.urgent'))='true') DESC,i.created_at,j.id LIMIT 5");
        $issues=[];foreach($open as $i){$m=json_decode($i['metadata_json'],true)??[];$issues[]=['id'=>'delivery-'.$i['id'],'reference'=>OrderReference::display($i['order_number']?:$i['public_reference']),'driver'=>$i['driver_name'],'reason'=>DeliveryIssueState::REASONS[$m['reason']??'']??'Delivery issue','urgent'=>!empty($m['urgent']),'sort_at'=>$i['created_at'],'at'=>OwnerProofViewer::time($i['created_at']),'url'=>'/apps/delivery/index.php?issue='.$i['id']];}
        $errandsReady=$this->exists('delivery_errand_events')&&$this->exists('delivery_errand_details');$errandIssues=null;
        if($errandsReady){
            $errandIssues=$this->scalar("SELECT COUNT(*) FROM delivery_errand_events WHERE action='issue_reported' AND created_at>=? AND created_at<?",[$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
            $openErrands=$this->rows("SELECT j.id,e.full_name driver_name,i.created_at,JSON_UNQUOTE(JSON_EXTRACT(i.after_json,'$.reason')) reason FROM delivery_errands j JOIN delivery_errand_details d ON d.errand_id=j.id LEFT JOIN ops_employees e ON e.id=j.driver_id JOIN delivery_errand_events i ON i.id=(SELECT MAX(ii.id) FROM delivery_errand_events ii WHERE ii.errand_id=j.id AND ii.action='issue_reported') WHERE d.state='blocked' AND j.collected_at IS NULL ORDER BY (JSON_UNQUOTE(JSON_EXTRACT(i.after_json,'$.reason'))='safety') DESC,i.created_at,j.id LIMIT 5");
            foreach($openErrands as $i)$issues[]=['id'=>'errand-'.$i['id'],'reference'=>'ER-'.str_pad((string)$i['id'],3,'0',STR_PAD_LEFT),'driver'=>$i['driver_name'],'reason'=>$i['reason']==='safety'?'Errand safety report':'Errand needs dispatch review','urgent'=>$i['reason']==='safety','sort_at'=>$i['created_at'],'at'=>OwnerProofViewer::time($i['created_at']),'url'=>'/apps/delivery/index.php?errands=1&errand='.$i['id']];
        }
        usort($issues,static fn($a,$b)=>($b['urgent']<=>$a['urgent'])?:strcmp($a['sort_at'],$b['sort_at'])?:strcmp($a['id'],$b['id']));$issues=array_slice($issues,0,5);foreach($issues as &$i)unset($i['sort_at']);unset($i);
        // Shop receipt and manual Cash Book reconciliation are not joined by this application.
        // Show a separately labelled custody subtotal, never an invented verified financial KPI.
        $cash=['verified_minor'=>null,'recorded_unconfirmed_minor'=>0,'declarations_minor'=>0,'available'=>true];
        try{
            $collectors=$this->rows("SELECT collected_by_employee_id id FROM delivery_receipts WHERE LOWER(payment_method)='cash' UNION SELECT employee_id id FROM delivery_events WHERE event_type='delivered'");$ledger=new CashHandover($this->db);
            foreach($collectors as $c){foreach($ledger->collections((int)$c['id']) as $r)$cash['recorded_unconfirmed_minor']+=$r['due_minor'];foreach($ledger->declarations((int)$c['id']) as $d)$cash['declarations_minor']+=$d['declared_minor'];}
        }catch(\DomainException $e){$cash['available']=false;$cash['recorded_unconfirmed_minor']=null;$cash['declarations_minor']=null;}
        $table=$this->table($p);$route=null;
        if($p['driver']){$name=$this->rows('SELECT full_name FROM ops_employees WHERE id=?',[$p['driver']])[0]['full_name'];$route=['driver_id'=>$p['driver'],'name'=>$name,'active'=>(int)($active[$p['driver']]??0),'available'=>false,'reason'=>'Live location is withheld: verified duty and an approved stop sequence are not recorded. App activity is not duty.','gps'=>null,'eta'=>null,'distance'=>null];}
        return ['generated_at'=>$now->format('D, j F Y · H:i:s'),'business_date'=>$now->format('Y-m-d'),'filters'=>$p,'summary'=>['drivers_on_duty'=>null,'drivers_total'=>count($drivers),'active_deliveries'=>array_sum($active),'issues_today'=>$issuesToday+($errandIssues??0),'delivery_issues_today'=>$issuesToday,'errand_issues_today'=>$errandIssues,'issues_scope'=>$issuesToday.' delivery + '.($errandIssues===null?'unknown':$errandIssues).' errand reports today; includes resolved.','cash'=>$cash],'drivers'=>$drivers,'urgent_issues'=>$issues,'deliveries'=>$table,'selected_route'=>$route];
    }
    public static function csvCell($value):string
    {
        $s=(string)$value;if(preg_match('/^[\s\x00-\x20]*[=+@\-]/u',$s)||preg_match('/^[\t\r\n]/',$s))$s="'".$s;
        return '"'.str_replace('"','""',$s).'"';
    }
    public function export(array $raw):string
    {
        $this->proof->authorize();$p=self::parameters($raw);$p['page']=1;$data=$this->table($p,2000,0);
        $lines=['Order,Customer,Driver,Delivery status,Payment status,Proof status'];
        foreach($data['rows'] as $r)$lines[]=implode(',',array_map([self::class,'csvCell'],[$r['reference'],$r['customer'],$r['driver'],$r['status'],$r['payment'],$r['proof']]));
        return "\xEF\xBB\xBF".implode("\r\n",$lines)."\r\n";
    }
}
