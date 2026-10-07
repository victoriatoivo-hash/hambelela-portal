<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;
use DateTimeImmutable;

/** Exclusive half-open intervals. Commands serialize on a stable scope lock. */
final class OwnershipPeriodEngine
{
    private $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}
    private function periods(string $table,string $where,array $args,string $at):array {
        $authority='accepted_by IS NOT NULL AND accepted_at<=?';$times=[$at];
        if($table==='epi_v2_ownership_periods'){
            $authority="($authority) OR (accepted_by IS NULL AND ownership_reason='approved_order_assignment' AND source='orders_sla_v1' AND effective_from<=?)";
            $times[]=$at;
        }else{
            $authority="($authority) OR (accepted_by IS NULL AND source='approved_front_roster' AND effective_from<=?)";
            $times[]=$at;
        }
        $s=$this->pdo->prepare("SELECT * FROM $table WHERE $where AND ($authority) AND effective_from<=? AND (effective_to IS NULL OR effective_to>?) ORDER BY effective_from");
        $s->execute(array_merge($args,$times,[$at,$at]));return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    public function ownerAt(string $module,string $reference,$at):?array {
        Support::requireModule($module);$time=Support::timestamp($at)->format('Y-m-d H:i:s');
        $rows=$this->periods('epi_v2_ownership_periods','module=? AND object_reference=?',[$module,$reference],$time);
        if(count($rows)>1)return null;
        $row=$rows[0]??null;
        if(!$row||$row['ownership_reason']==='front_desk_duty'){
            $duty=V2Store::one($this->pdo,'SELECT duty_key FROM epi_v2_object_duties WHERE module=? AND object_reference=?',[$module,$reference]);
            if($duty){$row=$this->dutyAt($duty['duty_key'],$time);if($row)$row['ownership_uuid']=$row['duty_uuid'];}
        }
        return $row&&!V2Store::absence($this->pdo,(int)$row['employee_id'],$time)?$row:null;
    }
    public function dutyAt(string $key,$at):?array {
        $time=Support::timestamp($at)->format('Y-m-d H:i:s');
        $rows=$this->periods('epi_v2_duty_periods',"duty_key=? AND superseded_at IS NULL AND responsibility_level IN('primary','coverage')",[$key],$time);
        if(count($rows)!==1)return null;
        return V2Store::absence($this->pdo,(int)$rows[0]['employee_id'],$time)?null:$rows[0];
    }
    public function assign(array $input):string {
        $module=Support::requireModule((string)($input['module']??''));$ref=trim((string)($input['object_reference']??''));
        $employee=(int)($input['employee_id']??0);$actor=(int)($input['assigned_by']??0);
        V2Store::employee($this->pdo,$employee);V2Store::employee($this->pdo,$actor);
        $activation=V2Store::activation($this->pdo);
        $directed=($input['authority']??'')==='owner_directed' && $actor===(int)($activation['approved_by']??0) && (V2Store::policy($this->pdo)['task_assignment_policy']??'')==='owner_directed';
        if($ref===''||((int)($input['accepted_by']??0)!==$employee&&!$directed))throw new RuntimeException('Accepted ownership is required');
        $from=Support::timestamp($input['effective_from']??null)->format('Y-m-d H:i:s');
        $accepted=Support::timestamp($input['accepted_at']??$from)->format('Y-m-d H:i:s');
        $to=!empty($input['effective_to'])?Support::timestamp($input['effective_to'])->format('Y-m-d H:i:s'):null;
        if($to!==null&&$to<=$from)throw new RuntimeException('Invalid interval');
        return V2Store::transaction($this->pdo,function()use($module,$ref,$employee,$actor,$from,$accepted,$to,$input){
            $scope=$module.'|'.$ref;V2Store::lock($this->pdo,$scope);
            if(V2Store::one($this->pdo,'SELECT id FROM epi_v2_ownership_periods WHERE module=? AND object_reference=? AND effective_from>=? LIMIT 1',[$module,$ref,$from]))throw new RuntimeException('Retroactive/duplicate ownership requires audited correction');
            $this->closeCurrent($module,$ref,Support::timestamp($from),(string)($input['transfer_reason']??'accepted reassignment'));
            $uuid=Support::uuid();
            $this->pdo->prepare('INSERT INTO epi_v2_ownership_periods(ownership_uuid,module,object_reference,employee_id,role_key,ownership_reason,effective_from,effective_to,assigned_by,accepted_by,accepted_at,transfer_reason,source,shift_id,exception_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$uuid,$module,$ref,$employee,$input['role_key']??null,$input['ownership_reason']??'direct_assignment',$from,$to,$actor,$input['accepted_by'],$accepted,$input['transfer_reason']??null,$input['source']??'portal',$input['shift_id']??null,$input['exception_id']??null]);
            V2Store::audit($this->pdo,$scope,$actor,'accepted assignment',null,$input+['ownership_uuid'=>$uuid]);return $uuid;
        });
    }
    public function closeCurrent(string $module,string $ref,DateTimeImmutable $at,string $reason):void {
        if(!$this->pdo->inTransaction())throw new RuntimeException('Ownership close requires locked transaction');
        $time=$at->format('Y-m-d H:i:s');
        $s=$this->pdo->prepare('SELECT * FROM epi_v2_ownership_periods WHERE module=? AND object_reference=? AND effective_from<? AND (effective_to IS NULL OR effective_to>?) FOR UPDATE');
        $s->execute([$module,$ref,$time,$time]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>1)throw new RuntimeException('Ambiguous ownership requires review');
        foreach($rows as$row){$this->pdo->prepare('UPDATE epi_v2_ownership_periods SET effective_to=? WHERE id=?')->execute([$time,$row['id']]);V2Store::audit($this->pdo,$module.'|'.$ref,(int)$row['assigned_by'],$reason,$row,array_replace($row,['effective_to'=>$time]));}
    }
    public function assignDuty(array $input):string {
        $employee=(int)($input['employee_id']??0);$actor=(int)($input['assigned_by']??0);$key=trim((string)($input['duty_key']??''));$level=$input['responsibility_level']??'primary';
        V2Store::employee($this->pdo,$employee);V2Store::employee($this->pdo,$actor);
        $from=Support::timestamp($input['effective_from']??null)->format('Y-m-d H:i:s');$to=!empty($input['effective_to'])?Support::timestamp($input['effective_to'])->format('Y-m-d H:i:s'):null;
        if(!$to||$to<=$from||$key===''||(int)($input['accepted_by']??0)!==$employee||!in_array($level,['primary','coverage'],true))throw new RuntimeException('Exclusive duty needs acceptance and bounded interval');
        if(Support::timestamp($input['accepted_at']??$from)>Support::timestamp($from))throw new RuntimeException('Coverage cannot begin before acceptance');
        if($level==='coverage'&&(empty($input['reason'])||empty($input['source'])))throw new RuntimeException('Coverage requires reason and approval source');
        return V2Store::transaction($this->pdo,function()use($input,$key,$level,$from,$to,$actor,$employee){
            V2Store::lock($this->pdo,'duty|'.$key);
            $s=$this->pdo->prepare('SELECT * FROM epi_v2_duty_periods WHERE duty_key=? AND superseded_at IS NULL AND effective_from<? AND (effective_to IS NULL OR effective_to>?) FOR UPDATE');$s->execute([$key,$to,$from]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as$row){
                if($level!=='coverage'||$row['responsibility_level']!=='primary')throw new RuntimeException('Overlapping exclusive duty requires explicit transfer');
                if($row['effective_from']<$from)$this->pdo->prepare('UPDATE epi_v2_duty_periods SET effective_to=? WHERE id=?')->execute([$from,$row['id']]);
                else $this->pdo->prepare('UPDATE epi_v2_duty_periods SET superseded_at=NOW() WHERE id=?')->execute([$row['id']]);
                V2Store::audit($this->pdo,'duty|'.$key,$actor,'coverage split',$row,['effective_to'=>$from,'coverage'=>$input]);
                if($row['effective_to']>$to)$this->insertDuty(array_replace($row,['effective_from'=>$to]));
            }
            $uuid=$this->insertDuty(['duty_key'=>$key,'employee_id'=>$employee,'responsibility_level'=>$level,'effective_from'=>$from,'effective_to'=>$to,'assigned_by'=>$actor,'accepted_by'=>$employee,'accepted_at'=>$input['accepted_at']??$from,'source'=>$input['source']??'owner_approved_duty','shift_id'=>$input['shift_id']??null,'exception_id'=>$input['exception_id']??null]);
            V2Store::audit($this->pdo,'duty|'.$key,$actor,$input['reason']??'accepted duty assignment',null,$input+['duty_uuid'=>$uuid]);return $uuid;
        });
    }
    private function insertDuty(array $row):string {
        $uuid=Support::uuid();$this->pdo->prepare('INSERT INTO epi_v2_duty_periods(duty_uuid,duty_key,employee_id,responsibility_level,effective_from,effective_to,assigned_by,accepted_by,accepted_at,source,shift_id,exception_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$uuid,$row['duty_key'],$row['employee_id'],$row['responsibility_level'],$row['effective_from'],$row['effective_to'],$row['assigned_by'],$row['accepted_by'],$row['accepted_at'],$row['source'],$row['shift_id']??null,$row['exception_id']??null]);return $uuid;
    }
    public function initiateHandover(array $input):string {
        $out=(int)($input['outgoing_employee_id']??0);$in=(int)($input['incoming_employee_id']??0);$actor=(int)($input['initiated_by']??0);$key=trim((string)($input['duty_key']??''));
        V2Store::employee($this->pdo,$out);V2Store::employee($this->pdo,$in);V2Store::employee($this->pdo,$actor);
        if($out===$in||$key===''||empty($input['transfer_reason']))throw new RuntimeException('Invalid handover');
        $uuid=Support::uuid();$at=Support::timestamp($input['initiated_at']??null)->format('Y-m-d H:i:s');
        if((int)($this->dutyAt($key,$at)['employee_id']??0)!==$out)throw new RuntimeException('Outgoing employee does not own duty');
        $this->pdo->prepare('INSERT INTO epi_v2_handovers(handover_uuid,duty_key,outgoing_employee_id,incoming_employee_id,initiated_at,initiated_by,transfer_reason,open_orders_json,waiting_customers_json,pending_payments_json,pending_courier_actions_json,unresolved_followups_json,customer_promises_json,source) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$uuid,$key,$out,$in,$at,$actor,$input['transfer_reason'],Support::json($input['open_orders']??[]),Support::json($input['waiting_customers']??[]),Support::json($input['pending_payments']??[]),Support::json($input['pending_courier_actions']??[]),Support::json($input['unresolved_followups']??[]),Support::json($input['customer_promises']??[]),$input['source']??'portal']);return $uuid;
    }
    public function acceptHandover(string $uuid,int $employee,$at=null):array {
        $time=Support::timestamp($at)->format('Y-m-d H:i:s');
        return V2Store::transaction($this->pdo,function()use($uuid,$employee,$time){
            $h=V2Store::one($this->pdo,"SELECT * FROM epi_v2_handovers WHERE handover_uuid=? AND status='pending' FOR UPDATE",[$uuid]);
            if(!$h||(int)$h['incoming_employee_id']!==$employee||$time<$h['initiated_at'])throw new RuntimeException('Invalid pending handover');
            $duty=$this->dutyAt($h['duty_key'],$time);if(!$duty||(int)$duty['employee_id']!==(int)$h['outgoing_employee_id'])throw new RuntimeException('Outgoing responsibility changed');
            $refs=[];foreach(['open_orders_json','waiting_customers_json','pending_payments_json','unresolved_followups_json','customer_promises_json','pending_courier_actions_json']as$key){foreach(json_decode($h[$key]??'[]',true)?:[]as$v){$ref=is_array($v)?(string)($v['reference']??$v['id']??''):(string)$v;if($ref!=='')$refs[($key==='pending_courier_actions_json'?'Courier':'Orders').'|'.$ref]=true;}}
            foreach(array_keys($refs)as$pair){[$module,$ref]=explode('|',$pair,2);$previous=$this->ownerAt($module,$ref,$time);if(!$previous||(int)$previous['employee_id']!==(int)$h['outgoing_employee_id'])throw new RuntimeException('Cannot transfer unowned work');
                $this->assign(['module'=>$module,'object_reference'=>$ref,'employee_id'=>$employee,'assigned_by'=>(int)$h['initiated_by'],'accepted_by'=>$employee,'accepted_at'=>$time,'effective_from'=>$time,'source'=>'handover:'.$uuid,'transfer_reason'=>$h['transfer_reason']]);}
            $newDuty=$this->assignDuty(['duty_key'=>$h['duty_key'],'employee_id'=>$employee,'assigned_by'=>(int)$h['initiated_by'],'accepted_by'=>$employee,'accepted_at'=>$time,'effective_from'=>$time,'effective_to'=>$duty['effective_to'],'responsibility_level'=>'coverage','reason'=>$h['transfer_reason'],'source'=>'handover:'.$uuid]);
            $this->pdo->prepare("UPDATE epi_v2_handovers SET status='accepted',accepted_at=?,accepted_by=? WHERE id=?")->execute([$time,$employee,$h['id']]);return ['handover_uuid'=>$uuid,'duty_uuid'=>$newDuty,'effective_from'=>$time];
        });
    }
}
