<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Read-only queue snapshot. Unattributed team work is never silently assigned. */
final class FrontHandoverChecklist
{
    public const NOTE_KEYS=['waiting_customers','pending_payments','courier_actions','customer_followups','customer_promises'];
    public static function notes(PDO $db,string $day): array {
        $audit=V2Store::one($db,"SELECT after_json FROM epi_v2_ownership_audits WHERE scope_key=? ORDER BY id DESC LIMIT 1",['front-plan|'.$day]);
        $data=json_decode($audit['after_json']??'{}',true)?:[];
        return $data['handover_notes']??array_fill_keys(self::NOTE_KEYS,'');
    }
    public static function validateNotes($json): array {
        $notes=json_decode((string)$json,true);
        if(!is_array($notes)||array_diff(array_keys($notes),self::NOTE_KEYS))throw new RuntimeException('Invalid handover notes.');
        $result=[];
        foreach(self::NOTE_KEYS as $key){$text=$notes[$key]??'';if(!is_string($text)||strlen($text)>1000)throw new RuntimeException('Keep each handover note under 1000 characters.');$result[$key]=trim($text);}
        return $result;
    }
    public static function read(PDO $db,int $outgoing,$now=null): array {
        $at=Support::timestamp($now)->format('Y-m-d H:i:s');
        $q=$db->query("SELECT d.deadline_uuid,d.module,d.object_reference,d.obligation_key,d.due_at,d.state,d.responsible_team
            FROM epi_v2_operational_deadlines d
            WHERE d.state IN('open','breached','needs_attribution') AND d.fulfilled_at IS NULL
              AND d.module NOT IN('Packing','Packing List')
              AND (d.responsible_team='front_desk'
                OR EXISTS(SELECT 1 FROM epi_v2_object_duties o WHERE o.module=d.module AND o.object_reference=d.object_reference AND o.duty_key='front_desk')
                OR EXISTS(SELECT 1 FROM epi_v2_ownership_periods o WHERE o.module=d.module AND o.object_reference=d.object_reference AND o.role_key IN('front_desk_admin','marketing_sales')))
            ORDER BY d.due_at,d.deadline_uuid");
        $deadlines=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $engine=new OwnershipPeriodEngine($db);$items=[];$unattributed=[];
        foreach($deadlines as $row) {
            $owner=$engine->ownerAt($row['module'],$row['object_reference'],$at);
            if($owner && (int)$owner['employee_id']!==$outgoing)continue;
            $row['responsible_employee_id']=$owner?(int)$owner['employee_id']:null;
            $row['overdue']=$row['due_at']<$at;
            if($owner)$items[]=$row;else $unattributed[]=$row;
        }
        $data=['outgoing_employee_id'=>$outgoing,'items'=>$items,'unattributed_team_items'=>$unattributed,
            'notes'=>self::notes($db,substr($at,0,10)),
            'coverage'=>'Tracked Front Desk obligations only. Unrecorded customer promises, waiting customers and follow-ups must be supplied in handover notes. Unclassified legacy work is not automatically assigned.'];
        // Exclude changing display-only overdue status from the review token.
        $stable=$data;
        foreach(['items','unattributed_team_items'] as $key)foreach($stable[$key] as &$row)unset($row['overdue']);
        unset($row);
        $data['review_token']=hash('sha256',(string)Support::json($stable));
        return $data;
    }
    public static function confirm(PDO $db,int $outgoing,array $input,$at): array {
        if(($input['work_reviewed']??null)!=='1')throw new RuntimeException('Review the handover work and confirm it before continuing.');
        $snapshot=self::read($db,$outgoing,$at);
        if(!hash_equals($snapshot['review_token'],(string)($input['review_token']??'')))throw new RuntimeException('The handover work changed. Refresh the checklist and review it again.');
        return $snapshot;
    }
}
