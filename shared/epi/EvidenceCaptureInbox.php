<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
require_once __DIR__.'/EvidenceStatus.php';

/** Read-only view of existing obligations; not a second scoring system. */
final class EvidenceCaptureInbox
{
    public static function employee(PDO $db,int $employee,string $period,?string $now=null):array
    {
        $at=Support::timestamp($now)->format('Y-m-d H:i:s');$to=Support::timestamp($period)->modify('+1 month')->format('Y-m-d');
        $q=$db->prepare('SELECT * FROM epi_v2_operational_deadlines WHERE starts_at<? AND (starts_at>=? OR state IN (\'open\',\'breached\',\'needs_attribution\')) ORDER BY due_at DESC,id DESC LIMIT 1000');
        $q->execute([$to,$period]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$out=[];$owners=new OwnershipPeriodEngine($db);
        $names=$db->query('SELECT id,full_name FROM ops_employees')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach($rows as $d){
            $when=$d['fulfilled_at']??min($at,$d['due_at']);
            $owner=$owners->ownerAt($d['module'],$d['object_reference'],$when);
            $incident=$d['breach_incident_uuid']?V2Store::one($db,'SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE incident_uuid=?',[$d['breach_incident_uuid']]):null;
            // Never turn a shared/team backlog into this employee's personal work.
            $originalExcluded=in_array($d['state'],['excused','cancelled'],true)&&(int)($d['responsible_employee_snapshot']??0)===$employee;
            if((int)($owner['employee_id']??0)!==$employee&&(int)($incident['responsible_employee_at_breach']??0)!==$employee&&!$originalExcluded)continue;
            $out[]=['module'=>$d['module'],'reference'=>$d['object_reference'],'obligation'=>$d['obligation_key'],
                'starts_at'=>$d['starts_at'],'due_at'=>$d['due_at'],'fulfilled_at'=>$d['fulfilled_at'],
                'fulfilled_by'=>$d['fulfilled_by'],'responsible_employee_id'=>$incident['responsible_employee_at_breach']??$owner['employee_id']??null,
                'responsible_name'=>$names[(int)($incident['responsible_employee_at_breach']??$owner['employee_id']??0)]??'Not proven',
                'fulfiller_name'=>$names[(int)($d['fulfilled_by']??0)]??'Not recorded',
                'status'=>EvidenceStatus::deadline($d,$at),'historical_only'=>(bool)$d['historical_backfill']];
        }
        $cash=[];$entries=[];
        foreach($out as $r)if($r['module']==='Bookkeeping'&&$r['obligation']==='enter_cash_transaction'&&!$r['fulfilled_at'])$cash[]=$r;
        $q=$db->prepare("SELECT id,created_at,cash_in,recorded_by,related_order_id,description FROM ops_cash_book_entries WHERE recorded_by=? AND created_at>=? AND created_at<? AND cash_in>0 AND deleted_at IS NULL AND status='active' ORDER BY created_at DESC LIMIT 200");$q->execute([$employee,$period,$to]);$entries=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        foreach($entries as &$entry){$origin=V2Store::one($db,"SELECT payload_json FROM epi_v2_outbox WHERE entity_type='cash_entry' AND entity_id=? AND action='created' ORDER BY id LIMIT 1",[$entry['id']]);$time=json_decode($origin['payload_json']??'{}',true)['metadata']['occurred_at']??null;$entry['native_created_at']=$entry['created_at'];$entry['created_at']=$time??('Original timing requires review; native timestamp '.$entry['created_at']);}unset($entry);
        return ['obligations'=>$out,'cash_obligations'=>$cash,'cash_entries'=>$entries,'limit'=>1000,'explanation'=>'Operational state is not a score. Missing proof, attribution and exclusions are checked separately.'];
    }
}
