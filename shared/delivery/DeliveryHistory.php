<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/OrderReference.php';
require_once __DIR__.'/EmployeeDriverSession.php';
require_once __DIR__.'/DeliveryIssueState.php';
require_once __DIR__.'/ProofStorage.php';

/** A read-only projection of canonical POD/issue audit events; never writes records. */
final class DeliveryHistory
{
    private bool $hasArchiveColumn;
    private \PDO $db;
    public function __construct(\PDO $db) {
        $this->db=$db;
        $this->hasArchiveColumn=(bool)$db->query("SHOW COLUMNS FROM ops_orders LIKE 'archived_at'")->fetch();
    }
    private function rows(string $sql,array $args=[]):array {$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC);}
    private static function json(string $alias,string $path):string {return "JSON_UNQUOTE(JSON_EXTRACT($alias.metadata_json,'$.$path'))";}
    public static function filters(array $p,?\DateTimeImmutable $now=null):array
    {
        $now=($now??new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Africa/Windhoek'))->setTime(0,0);
        $period=$p['period']??'week';$status=$p['status']??'all';$search=$p['search']??'';
        if(!is_string($period)||!in_array($period,['today','week','seven','month','all','custom'],true)||!is_string($status)||!in_array($status,['all','delivered','issue'],true))throw new \DomainException('Choose a valid date and status filter.');
        if(!is_string($search)||!mb_check_encoding($search,'UTF-8')||mb_strlen($search)>100||preg_match('/[\x00-\x1f]/',$search))throw new \DomainException('Search accepts up to 100 characters.');
        $from=$now;$to=$now->modify('+1 day');
        if($period==='week'){$from=$now->modify('-'.((int)$now->format('N')-1).' days');$to=$from->modify('+7 days');}
        if($period==='seven')$from=$now->modify('-6 days');
        if($period==='month'){$from=$now->modify('first day of this month');$to=$from->modify('+1 month');}
        if($period==='all')$from=null;
        if($period==='custom'){
            $parse=static function($s){if(!is_string($s)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$s))throw new \DomainException('Enter valid From and To dates.');$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$s,new \DateTimeZone('Africa/Windhoek'));if(!$d||$d->format('Y-m-d')!==$s||$s<'2000-01-01')throw new \DomainException('Enter valid dates from 2000 onwards.');return $d;};
            $from=$parse($p['from']??null);$end=$parse($p['to']??null);
            if($from>$end||$from->diff($end)->days>366)throw new \DomainException('From must precede To. Use at most 367 days per custom range, or choose All time.');$to=$end->modify('+1 day');
        }
        return ['period'=>$period,'status'=>$status,'search'=>ltrim(trim($search),'#'),'from'=>$from?$from->format('Y-m-d'):null,'to'=>$to->modify('-1 day')->format('Y-m-d'),'from_utc'=>$from?$from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null,'to_utc'=>$to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'today'=>$now->format('Y-m-d'),'timezone'=>'Africa/Windhoek'];
    }
    /** Proof validity is based on the server's immutable policy/evidence receipt, not order status. */
    private function projection():string
    {
        $j=static fn($p)=>self::json('e',$p);$proof=$j('proof.receipt');$incident=$j('incident_id');
        $files=[];$sign=[];$photo=[];
        for($i=0;$i<2;$i++){$pre="proof.evidence[$i]";$valid="(".$j("$pre.id")." REGEXP '^[a-f0-9]{48}$' AND ".$j("$pre.stored_sha256")." REGEXP '^[a-f0-9]{64}$' AND ".$j("$pre.mime")."='image/png' AND ".$j("$pre.kind")." IN ('photo','signature'))";$files[]=$valid;$sign[]="($valid AND ".$j("$pre.kind")."='signature')";$photo[]="($valid AND ".$j("$pre.kind")."='photo')";}
        $hasSign='('.implode(' OR ',$sign).')';$hasPhoto='('.implode(' OR ',$photo).')';$policy=$j('proof.policy.evidence');
        $validProof="($proof REGEXP '^[a-f0-9]{32}$' AND ".$j('proof.driver_id')."=CAST(e.employee_id AS CHAR) AND ".$j('proof.delivery_id')."=CAST(e.delivery_id AS CHAR) AND ".$j('proof.recipient.acknowledged')."='true' AND CHAR_LENGTH(TRIM(".$j('proof.recipient.recipient_name').")) BETWEEN 1 AND 120 AND ".$j('proof.recipient.recipient_type')." IN ('customer','representative') AND (".$j('proof.recipient.recipient_type')."='customer' OR (".$j('proof.policy.representative')."='relationship' AND CHAR_LENGTH(TRIM(".$j('proof.recipient.relationship').")) BETWEEN 1 AND 200)) AND JSON_LENGTH(e.metadata_json,'$.proof.evidence') BETWEEN 1 AND 2 AND {$files[0]} AND (JSON_LENGTH(e.metadata_json,'$.proof.evidence')=1 OR {$files[1]}) AND (($policy='signature' AND $hasSign) OR ($policy='both' AND $hasSign AND $hasPhoto) OR ($policy='one' AND ($hasSign OR $hasPhoto))))";
        $validProof.=" AND (".$j('proof.order_id')."=CAST(j.order_id AS CHAR) OR (j.order_id IS NULL AND ".$j('proof.order_id')."='null'))";
        $validIssue="($incident REGEXP '^[a-f0-9]{32}$' AND ".$j('driver_id')."=CAST(e.employee_id AS CHAR) AND ".$j('id')."=CAST(e.delivery_id AS CHAR) AND ".$j('reason')." IN ('not_answering','wrong_address','unavailable','other') AND CHAR_LENGTH(".$j('notes').")>=12)";
        // Current source context is exposed only while the original actor still owns it.
        $archive=$this->hasArchiveColumn?' AND o.archived_at IS NULL':'';
        $visible="(j.driver_employee_id=e.employee_id AND (j.source<>'hambelela' OR (o.id IS NOT NULL AND o.deleted_at IS NULL $archive AND o.status NOT IN ('deleted','trash','archived'))))";
        $ref="COALESCE(NULLIF(o.order_number,''),j.public_reference)";
        $dedup="NOT EXISTS(SELECT 1 FROM delivery_events earlier WHERE earlier.delivery_id=e.delivery_id AND earlier.employee_id=e.employee_id AND earlier.event_type=e.event_type AND earlier.id<e.id AND ((e.event_type='delivered' AND $proof REGEXP '^[a-f0-9]{32}$' AND ".self::json('earlier','proof.receipt')."=$proof) OR (e.event_type='delivery_issue_reported' AND $incident REGEXP '^[a-f0-9]{32}$' AND ".self::json('earlier','incident_id')."=$incident)))";
        return "SELECT e.id,e.delivery_id,e.employee_id,e.created_at,e.event_type,e.metadata_json,j.order_id,j.status current_status,
          CASE WHEN e.event_type='delivered' AND $validProof THEN 'delivered' WHEN e.event_type='delivery_issue_reported' AND $validIssue THEN 'issue' ELSE 'review' END outcome,
          CASE WHEN j.order_id IS NOT NULL THEN CONCAT('order:',j.order_id) ELSE CONCAT('assignment:',e.delivery_id) END order_key,
          CASE WHEN $visible THEN 1 ELSE 0 END context_visible,
          CASE WHEN $visible THEN $ref ELSE NULL END reference,
          CASE WHEN $visible THEN j.customer_name ELSE NULL END customer,
          CASE WHEN $visible THEN j.area ELSE NULL END area
          FROM delivery_events e LEFT JOIN delivery_jobs j ON j.id=e.delivery_id LEFT JOIN ops_orders o ON o.id=j.order_id
          WHERE e.employee_id=? AND e.event_type IN ('delivered','delivery_issue_reported','failed') AND $dedup";
    }
    private function query(array $f,int $driver,int $max):array
    {
        $args=[$driver,$max];$where='e.id<=?';if($f['from_utc']){$where.=' AND e.created_at>=?';$args[]=$f['from_utc'];}$where.=' AND e.created_at<?';$args[]=$f['to_utc'];
        $sql='SELECT * FROM ('.$this->projection().' AND '.$where.') h WHERE 1=1';
        if($f['status']!=='all'){$sql.=' AND outcome=?';$args[]=$f['status'];}
        if($f['search']!==''){$sql.=" AND context_visible=1 AND (reference LIKE ? ESCAPE '=' OR customer LIKE ? ESCAPE '=' OR area LIKE ? ESCAPE '=')";$like='%'.str_replace(['=','%','_'],['==','=%','=_'],$f['search']).'%';array_push($args,$like,$like,$like);}
        return [$sql,$args];
    }
    private static function masked(?string $name):string {$parts=preg_split('/\s+/u',trim($name??''));return ($parts[0]?:'Customer').(count($parts)>1?' '.mb_substr(end($parts),0,1).'.':'');}
    private static function item(array $r):array
    {
        $t=(new \DateTimeImmutable($r['created_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Windhoek'));
        return ['event_id'=>(int)$r['id'],'outcome'=>$r['outcome'],'recorded_at_utc'=>str_replace(' ','T',$r['created_at']).'Z','local_date'=>$t->format('Y-m-d'),'local_time'=>$t->format('H:i'),'day_label'=>$t->format('j F Y'),'reference'=>OrderReference::display($r['reference']),'customer'=>(int)$r['context_visible']?self::masked($r['customer']):'Previous assignment','area'=>$r['area']?:((int)$r['context_visible']?'Area not recorded':'Details restricted'),'context_visible'=>(bool)$r['context_visible']];
    }
    public function page(int $driver,array $p,?\DateTimeImmutable $now=null):array
    {
        EmployeeDriverSession::actor($this->db,$driver);$f=self::filters($p,$now);$fingerprint=hash('sha256',json_encode([$driver,$f],JSON_THROW_ON_ERROR));$cursor=$p['cursor']??null;$max=(int)$this->rows('SELECT COALESCE(MAX(id),0) id FROM delivery_events WHERE employee_id=?',[$driver])[0]['id'];$at=null;$last=null;
        if($cursor!==null){if(!is_string($cursor)||strlen($cursor)>900)throw new \DomainException('Invalid history cursor.');$c=json_decode((string)base64_decode($cursor,true),true);if(!is_array($c)||($c['scope']??'')!==$fingerprint||!is_int($c['max']??null)||$c['max']<0||$c['max']>$max||!is_int($c['id']??null)||$c['id']<1||!is_string($c['at']??null)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$c['at']))throw new \DomainException('History filters changed. Refresh before loading more.');$max=$c['max'];$at=$c['at'];$last=$c['id'];}
        // A repeatable-read snapshot keeps list/count membership aligned during this request.
        if($this->db->inTransaction())throw new \LogicException('History owns its read snapshot.');$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();
        try{[$sql,$args]=$this->query($f,$driver,$max);$totals=$this->rows("SELECT COUNT(DISTINCT order_key) total_deliveries,COUNT(DISTINCT CASE WHEN outcome='delivered' THEN order_key END) completed,COALESCE(SUM(outcome='issue'),0) issues_raised,COUNT(*) total_outcomes,COALESCE(SUM(outcome='review'),0) needs_review,COALESCE(SUM(CRC32(CONCAT(id,':',context_visible,':',COALESCE(reference,''),':',COALESCE(customer,''),':',COALESCE(area,'')))),0) context_revision FROM ($sql) totals",$args)[0];
            if($at){$sql.=' AND (created_at<? OR (created_at=? AND id<?))';array_push($args,$at,$at,$last);}$rows=$this->rows($sql.' ORDER BY created_at DESC,id DESC LIMIT 26',$args);$more=count($rows)>25;if($more)array_pop($rows);$next=null;if($more){$r=end($rows);$next=base64_encode(json_encode(['scope'=>$fingerprint,'max'=>$max,'at'=>$r['created_at'],'id'=>(int)$r['id']],JSON_THROW_ON_ERROR));}$this->db->commit();return ['filters'=>$f,'totals'=>array_map('intval',$totals),'items'=>array_map([self::class,'item'],$rows),'cursor_next'=>$next,'snapshot_event_id'=>$max];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Owner-approved weekly rate: current released assignments scheduled this week,
     * signed completions this week / non-cancelled assignments, including unresolved work.
     * Historical totals stay independent; later reassignment/cancellation does not erase audit. */
    public function weeklyCompletion(int $driver,?\DateTimeImmutable $now=null):array
    {
        EmployeeDriverSession::actor($this->db,$driver);
        $f=self::filters(['period'=>'week'],$now);
        $archive=$this->hasArchiveColumn?' AND o.archived_at IS NULL':'';
        $sql="SELECT COUNT(DISTINCT j.id) assigned,
          COUNT(DISTINCT CASE WHEN h.outcome='delivered' AND h.created_at>=? AND h.created_at<?
            AND j.status IN ('delivered','awaiting_reconciliation','completed')
            AND (JSON_UNQUOTE(JSON_EXTRACT(h.metadata_json,'$.proof.evidence[0].kind'))='signature'
              OR JSON_UNQUOTE(JSON_EXTRACT(h.metadata_json,'$.proof.evidence[1].kind'))='signature')
            THEN j.id END) completed
          FROM delivery_jobs j LEFT JOIN ops_orders o ON o.id=j.order_id
          LEFT JOIN (".$this->projection().") h ON h.delivery_id=j.id
          WHERE j.driver_employee_id=? AND j.scheduled_date>=? AND j.scheduled_date<=? AND j.status<>'cancelled'
            AND EXISTS(SELECT 1 FROM delivery_events released WHERE released.delivery_id=j.id AND released.event_type IN ('arranged','partner_arranged'))
            AND (j.source<>'hambelela' OR (o.id IS NOT NULL AND o.deleted_at IS NULL $archive AND o.status NOT IN ('cancelled','canceled','deleted','trash','archived','refunded')))";
        $r=$this->rows($sql,[$f['from_utc'],$f['to_utc'],$driver,$driver,$f['from'],$f['to']])[0];
        $assigned=(int)$r['assigned'];$completed=(int)$r['completed'];
        return ['assigned'=>$assigned,'completed'=>$completed,'rate'=>$assigned?round(100*$completed/$assigned,1):null];
    }
    public function detail(int $driver,int $event):array
    {
        EmployeeDriverSession::actor($this->db,$driver);$r=$this->rows($this->projection().' AND e.id=?',[$driver,$event])[0]??null;if(!$r)throw new \DomainException('History record unavailable.',404);
        $out=self::item($r);$out['current_status']=$r['current_status'];$out['warning']=$r['outcome']==='review'?'No verified proof or supported issue receipt. Dispatch review required.':null;$out['evidence']=[];$out['decisions']=[];$out['issue_reason']=null;
        if(!$out['context_visible']){$out['warning']='Assignment or source access changed. Only your minimal audit entry is retained.';return $out;}
        $m=json_decode($r['metadata_json'],true,64,JSON_THROW_ON_ERROR);
        if($r['outcome']==='delivered'){$out['proof_receipt']=$m['proof']['receipt'];$out['evidence_policy']=$m['proof']['policy']['evidence'];$files=$m['proof']['evidence'];}
        elseif($r['outcome']==='issue'){$out['issue_reference']=$m['incident_id'];$out['issue_reason']=DeliveryIssueState::REASONS[$m['reason']];$out['issue_notes']=$m['notes'];$files=$m['evidence']??[];
            $out['decisions']=array_map(static function($d){$v=json_decode($d['metadata_json'],true,64,JSON_THROW_ON_ERROR);return ['event_id'=>(int)$d['id'],'recorded_at'=>$d['created_at'].' UTC','decision'=>DeliveryIssueState::DECISIONS[$v['decision']??'']??'Dispatch review','instruction'=>$v['instruction']??''];},$this->rows("SELECT id,created_at,metadata_json FROM delivery_events WHERE delivery_id=? AND id>? AND event_type='dispatch_resolution_recorded' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.incident_id'))=? ORDER BY id LIMIT 100",[$r['delivery_id'],$event,$m['incident_id']]));
        }else $files=[];
        foreach(is_array($files)?$files:[] as $i=>$file){try{$path=ProofStorage::configured()->path($file['id']??'');$available=is_file($path)&&hash_equals($file['stored_sha256']??'',hash_file('sha256',$path));}catch(\LogicException $e){$available=false;}$out['evidence'][]=['part'=>(int)$i,'kind'=>in_array($file['kind']??'', ['photo','signature'],true)?$file['kind']:'Evidence','available'=>$available];}
        if($r['current_status']==='cancelled')$out['warning']='Dispatch later cancelled this assignment. Its original outcome remains in the audit.';
        return $out;
    }
    public function evidence(int $driver,int $event,int $part):string
    {
        $d=$this->detail($driver,$event);if(!$d['context_visible']||!in_array($d['outcome'],['delivered','issue'],true)||$part<0||$part>1)throw new \DomainException('Evidence unavailable.',404);
        $r=$this->rows('SELECT metadata_json FROM delivery_events WHERE id=? AND employee_id=?',[$event,$driver])[0]??null;$m=json_decode($r['metadata_json']??'{}',true,64,JSON_THROW_ON_ERROR);$file=($d['outcome']==='delivered'?($m['proof']['evidence']??[]):($m['evidence']??[]))[$part]??null;
        if(!$file||($file['mime']??'')!=='image/png')throw new \DomainException('Evidence unavailable.',404);try{$path=ProofStorage::configured()->path($file['id']);}catch(\LogicException $e){throw new \DomainException('Evidence unavailable. Contact dispatch.',404);}if(!is_file($path)||!hash_equals($file['stored_sha256']??'',hash_file('sha256',$path)))throw new \DomainException('Evidence unavailable. Contact dispatch.',404);return $path;
    }
}
