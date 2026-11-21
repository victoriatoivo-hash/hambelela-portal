<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/ErrandData.php';
require_once __DIR__.'/ErrandPolicy.php';

/** Versioned commands over existing errand assignments. Never calls Orders, POS or cash adapters. */
final class ErrandWorkflow
{
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    private function exec(string $sql,array $v=[]):void {$this->db->prepare($sql)->execute($v);}
    private function event(int $id,int $actor,string $action,array $data):int
    {
        $this->exec('INSERT INTO delivery_errand_events(errand_id,actor_id,action,after_json,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())',[$id,$actor,$action,json_encode($data,JSON_THROW_ON_ERROR)]);return (int)$this->db->lastInsertId();
    }
    private function requestEvent(int $id,int $actor,string $action,array $data):int
    {
        $this->exec('INSERT INTO delivery_errand_request_events(request_id,actor_id,action,metadata_json,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())',[$id,$actor,$action,json_encode($data,JSON_THROW_ON_ERROR)]);return (int)$this->db->lastInsertId();
    }
    /** Existing inbox tables participate in the SAME transaction. No fake sent state or DDL. */
    private function notify(string $type,int $id,int $event,string $title,string $message,?int $driver=null,bool $urgent=false):void
    {
        $ids=[];
        if($driver!==null){EmployeeDriverSession::actor($this->db,$driver);$ids=[$driver];}
        else foreach(ErrandData::query($this->db,"SELECT id FROM ops_employees WHERE status='active'") as $row){try{$a=ErrandData::actor($this->db,(int)$row['id']);if($a['role']!=='delivery_driver')$ids[]=$a['id'];}catch(\DomainException $e){}}
        if(!$ids)throw new \DomainException('No active dispatch recipient is configured. Nothing was saved; contact dispatch.');
        $link='/apps/delivery/index.php?errands=1'.($type==='delivery_errand'?'&errand='.$id:'&request='.$id);
        $this->exec('INSERT INTO notifications(title,message,module,related_type,related_id,priority,deduplication_key,action_link,created_at) VALUES(?,?,\'delivery\',?,?,?,?,?,UTC_TIMESTAMP())',[$title,$message,$type,$id,$urgent?'urgent':'normal','errand:'.$type.':'.$event.':'.($driver??'dispatch'),$link]);$notice=(int)$this->db->lastInsertId();
        foreach($ids as $recipient)$this->exec('INSERT INTO notification_recipients(notification_id,employee_id) VALUES(?,?)',[$notice,$recipient]);
    }
    private function create(int $actor,array $v,string $uuid):array
    {
        $driver=(int)($v['driver_id']??0);EmployeeDriverSession::actor($this->db,$driver);
        $p=ErrandPolicy::normalize($v);$place=ErrandPolicy::text($v['place']??null,'Location',3,255);$contact=ErrandPolicy::text($v['contact']??null,'Contact person',2,190);$contents=ErrandPolicy::text($v['instructions']??null,'Instructions',12);
        if(!in_array($v['payment_state']??'', ['paid','unpaid'],true))throw new \DomainException('Choose payment instructions: paid or not paid.');
        $amount=$v['amount']??'';if($amount!==''&&!preg_match('/^\d{1,9}(\.\d{1,2})?$/D',(string)$amount))throw new \DomainException('Enter a valid requested amount.');
        $payer=isset($v['payer'])&&trim((string)$v['payer'])!==''?ErrandPolicy::text($v['payer'],'Expense instructions',1,190):'';
        $this->exec('INSERT INTO delivery_errands(created_by,driver_id,request_key,place,contact_name,contents,payment_state,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())',[$actor,$driver,str_replace('-','',$uuid),$place,$contact,$contents,$v['payment_state']]);$id=(int)$this->db->lastInsertId();
        $this->exec('INSERT INTO delivery_errand_details(errand_id,amount,payer,updated_at) VALUES(?,?,?,UTC_TIMESTAMP())',[$id,$amount===''?null:$amount,$payer]);
        $j=ErrandData::row($this->db,$id);$event=$this->event($id,$actor,'sent',['assignment'=>$j]);
        $this->event($id,$actor,'policy_set',['policy'=>$p,'fingerprint'=>ErrandData::fingerprint($j)]);
        $this->notify('delivery_errand',$id,$event,'New errand assigned','A shop errand is ready. Open the assignment to review the instructions.',$driver,$p['priority']);
        return ['id'=>$id,'status'=>'assigned','receipt'=>$event,'evidence'=>[]];
    }
    public function command(int $employee,string $uuid,string $action,int $id,int $version,array $v,array $evidence=[]):array
    {
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid))throw new \DomainException('Invalid request reference.');
        if(!in_array($action,['request','create','approve','reject','configure','revise','reopen','start','complete','issue','resume','cancel'],true))throw new \DomainException('Unknown errand action.');
        if($this->db->inTransaction())throw new \LogicException('Errand command owns its transaction.');
        $hash=hash('sha256',json_encode([$id,$version,$action,$v,array_map(static fn($f)=>[$f['kind'],$f['sha256']],$evidence)],JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try{
            $request=in_array($action,['approve','reject'],true);$new=in_array($action,['request','create'],true);
            $lock=$new?'SELECT id FROM ops_employees WHERE id=? FOR UPDATE':($request?'SELECT id FROM delivery_errand_requests WHERE id=? FOR UPDATE':'SELECT id FROM delivery_errands WHERE id=? FOR UPDATE');
            if(!ErrandData::query($this->db,$lock,[$new?$employee:$id]))throw new \DomainException('Record unavailable or access removed.');
            $a=ErrandData::actor($this->db,$employee);$driver=$a['role']==='delivery_driver';
            if($driver!==in_array($action,['request','start','complete','issue'],true))throw new \DomainException('This action is not permitted for your role.');
            $j=$new||$request?null:ErrandData::authorized($this->db,$employee,$id);
            $r=$request?ErrandData::query($this->db,'SELECT * FROM delivery_errand_requests WHERE id=?',[$id])[0]:null;
            $key='employee:'.$employee;$old=ErrandData::query($this->db,'SELECT * FROM delivery_requests WHERE actor_key=? AND request_uuid=? FOR UPDATE',[$key,$uuid]);
            if($old){if($old[0]['request_hash']!==$hash||$old[0]['action']!=='errand_'.$action)throw new \DomainException('This request reference was already used with different details.');$result=json_decode($old[0]['response_json'],true,512,JSON_THROW_ON_ERROR);$this->db->commit();return $result;}
            foreach($evidence as $file){$path=ProofStorage::configured()->path($file['id']);if(!in_array($file['kind'],['photo','signature'],true)||!is_file($path)||!hash_equals($file['stored_sha256'],hash_file('sha256',$path)))throw new \DomainException('Evidence is unavailable. Select it again.');}
            if($new){
                if($action==='create')$result=$this->create($employee,$v,$uuid);
                else{
                    $data=['title'=>ErrandPolicy::text($v['title']??null,'Task name',4,110),'reason'=>ErrandPolicy::text($v['reason']??null,'Reason',12),'place'=>ErrandPolicy::text($v['place']??null,'Location',3,255),'urgent'=>($v['urgent']??'')==='1'];
                    $this->exec('INSERT INTO delivery_errand_requests(requested_by,title,reason,place,urgent,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$employee,$data['title'],$data['reason'],$data['place'],(int)$data['urgent']]);$rid=(int)$this->db->lastInsertId();
                    $event=$this->requestEvent($rid,$employee,'requested',$data);$this->notify('delivery_errand_request',$rid,$event,'Errand request awaiting approval','A driver suggested an errand. Review it before creating any assignment.',null,$data['urgent']);
                    $result=['request_id'=>$rid,'status'=>'pending','receipt'=>$event,'evidence'=>[]];
                }
            }elseif($request){
                if((int)$r['revision']!==$version||$r['status']!=='pending')throw new \DomainException('This request changed or was already reviewed. Reload it.');
                if((int)$r['requested_by']===$employee)throw new \DomainException('You cannot approve your own request.');
                $note=ErrandPolicy::text($v['notes']??null,'Review notes',12);$created=$action==='approve'?$this->create($employee,$v,$uuid):null;
                $this->exec('UPDATE delivery_errand_requests SET status=?,revision=revision+1,errand_id=? WHERE id=?',[$action==='approve'?'approved':'rejected',$created['id']??null,$id]);
                $event=$this->requestEvent($id,$employee,$action,['notes'=>$note,'errand_id'=>$created['id']??null]);
                // A requester who is no longer active does not receive private updates; the audit remains.
                try{EmployeeDriverSession::actor($this->db,(int)$r['requested_by']);$this->notify('delivery_errand_request',$id,$event,'Your errand request was reviewed','Open Errands to view the dispatch decision.',(int)$r['requested_by']);}catch(\DomainException $e){if(strpos($e->getMessage(),'Active Driver')===false)throw $e;}
                $result=['request_id'=>$id,'status'=>$action==='approve'?'approved':'rejected','errand_id'=>$created['id']??null,'receipt'=>$event,'evidence'=>[]];
            }else{
                if((int)$j['revision']!==$version)throw new \DomainException('This errand changed. Close the form and review the latest details.');
                $state=ErrandData::status($j);if(in_array($state,['completed','cancelled'],true)&&!($action==='reopen'&&$state==='completed'))throw new \DomainException('Closed errands are read-only. Contact dispatch for an audited correction.');
                $p=ErrandData::policy($j,ErrandData::events($this->db,$id));$data=[];$next=$j['state'];$eventAction=$action;$completed=false;
                if($action==='revise'){
                    if($state==='in_progress')throw new \DomainException('Hold the errand for review before reassigning or changing its instructions.');
                    $newDriver=(int)($v['driver_id']??0);EmployeeDriverSession::actor($this->db,$newDriver);$p=ErrandPolicy::normalize($v);
                    $note=ErrandPolicy::text($v['notes']??null,'Change reason',12);$place=ErrandPolicy::text($v['place']??null,'Location',3,255);$contact=ErrandPolicy::text($v['contact']??null,'Contact person',2,190);$instructions=ErrandPolicy::text($v['instructions']??null,'Instructions',12);
                    if(!in_array($v['payment_state']??'',['paid','unpaid'],true))throw new \DomainException('Choose payment instructions.');$amount=$v['amount']??'';if($amount!==''&&!preg_match('/^\d{1,9}(\.\d{1,2})?$/D',(string)$amount))throw new \DomainException('Invalid requested amount.');$payer=trim((string)($v['payer']??''));if(mb_strlen($payer)>190)throw new \DomainException('Payment instructions are too long.');
                    $this->exec('UPDATE delivery_errands SET driver_id=?,place=?,contact_name=?,contents=?,payment_state=?,seen_at=NULL WHERE id=?',[$newDriver,$place,$contact,$instructions,$v['payment_state'],$id]);
                    $this->exec('UPDATE delivery_errand_details SET amount=?,payer=? WHERE errand_id=?',[$amount===''?null:$amount,$payer,$id]);
                    $data=['notes'=>$note,'previous'=>$j,'new_driver'=>$newDriver];$next='open';$eventAction='edit';
                    $j=ErrandData::row($this->db,$id);$this->event($id,$employee,'policy_set',['policy'=>$p,'fingerprint'=>ErrandData::fingerprint($j)]);
                }elseif($action==='reopen'){
                    if($state!=='completed')throw new \DomainException('Only a completed errand may be reopened for correction.');$data=['notes'=>ErrandPolicy::text($v['notes']??null,'Correction reason',12),'previous_completed_at'=>$j['collected_at']];$next='blocked';$eventAction='reopen';$this->exec('UPDATE delivery_errands SET collected_at=NULL WHERE id=?',[$id]);
                }elseif($action==='configure'){
                    if($state==='in_progress')throw new \DomainException('Report an issue before changing an in-progress assignment.');
                    $p=ErrandPolicy::normalize($v);$data=['policy'=>$p,'fingerprint'=>ErrandData::fingerprint($j)];$eventAction='policy_set';
                }elseif($action==='start'){
                    if($state!=='assigned'||!$p)throw new \DomainException('Dispatch must confirm the instructions and proof policy before this errand starts.');$next='started';$eventAction='started';
                }elseif($action==='issue'){
                    if(!in_array($state,['assigned','in_progress'],true))throw new \DomainException('This errand is already awaiting dispatch.');
                    if(!in_array($v['reason']??'', ['quantity_difference','wrong_location','supplier_unavailable','damage','safety','other'],true))throw new \DomainException('Choose an issue reason.');
                    $data=['reason'=>$v['reason'],'notes'=>ErrandPolicy::text($v['notes']??null,'Issue details',12),'evidence'=>$evidence];$next='blocked';$eventAction='issue_reported';
                }elseif($action==='complete'){
                    if($state!=='in_progress'||!$p)throw new \DomainException('Start this errand and resolve dispatch holds before completing.');
                    $data=ErrandPolicy::completion($p,$v,$evidence);$data['policy']=$p;
                    $completed=!$data['discrepancy'];$next=$completed?'open':'blocked';$eventAction=$completed?'completed':'issue_reported';if(!$completed)$data['reason']='quantity_difference';
                }elseif($action==='resume'){
                    if($state!=='needs_review'||!$p)throw new \DomainException('Only a held errand with confirmed requirements may resume.');$next='started';$eventAction='dispatch_resumed';$data=['notes'=>ErrandPolicy::text($v['notes']??null,'Dispatch instructions',12)];
                }elseif($action==='cancel'){$next='cancelled';$eventAction='dispatch_cancelled';$data=['notes'=>ErrandPolicy::text($v['notes']??null,'Cancellation reason',12)];}
                $this->exec('INSERT IGNORE INTO delivery_errand_details(errand_id) VALUES(?)',[$id]);
                $this->exec('UPDATE delivery_errand_details SET state=?,revision=revision+1,note=?,updated_at=UTC_TIMESTAMP(),claim_key=NULL,claim_until=NULL,snooze_until=NULL WHERE errand_id=?',[$next,$data['notes']??$j['note'],$id]);
                if($driver)$this->exec('UPDATE delivery_errands SET seen_at=COALESCE(seen_at,UTC_TIMESTAMP()) WHERE id=?',[$id]);
                if($completed)$this->exec('UPDATE delivery_errands SET collected_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
                $event=$this->event($id,$employee,$eventAction,$data);
                $this->notify('delivery_errand',$id,$event,$driver?'Driver errand update':'Dispatch errand update','Errand ER-'.$id.' · '.str_replace('_',' ',$eventAction).'. Open for the saved details.',$driver?null:(int)$j['driver_id'],($data['reason']??'')==='safety');
                $result=['id'=>$id,'status'=>$completed?'completed':($next==='blocked'?'needs_review':($next==='started'?'in_progress':($next==='cancelled'?'cancelled':'assigned'))),'receipt'=>$event,'evidence'=>$data['evidence']??[]];
            }
            $result['saved_at']=gmdate('Y-m-d H:i:s');$result['notification']='inbox_recorded';
            $this->exec('INSERT INTO delivery_requests(actor_key,request_uuid,action,request_hash,response_json) VALUES(?,?,?,?,?)',[$key,$uuid,'errand_'.$action,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            $this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
