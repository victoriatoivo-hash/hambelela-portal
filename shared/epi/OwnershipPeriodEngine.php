<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Accepted, time-bounded ownership used for breach-time attribution. */
final class OwnershipPeriodEngine
{
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function ownerAt(string $module, string $objectReference, $at): ?array
    {
        $module = Support::requireModule($module);
        $reference = trim($objectReference);
        if ($reference === '') throw new InvalidArgumentException('Ownership requires an object reference.');
        $time = Support::timestamp($at)->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM epi_v2_ownership_periods
             WHERE module=? AND object_reference=? AND accepted_at IS NOT NULL
               AND effective_from<=? AND (effective_to IS NULL OR effective_to>?)
             ORDER BY effective_from DESC,id DESC LIMIT 1'
        );
        $stmt->execute([$module, $reference, $time, $time]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function dutyAt(string $dutyKey,$at):?array
    {
        $time=Support::timestamp($at)->format('Y-m-d H:i:s');$stmt=$this->pdo->prepare("SELECT * FROM epi_v2_duty_periods WHERE duty_key=? AND accepted_at IS NOT NULL AND effective_from<=? AND(effective_to IS NULL OR effective_to>?) ORDER BY FIELD(responsibility_level,'primary','coverage','secondary'),effective_from DESC,id DESC LIMIT 1");$stmt->execute([trim($dutyKey),$time,$time]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return$row?:null;
    }

    public function assignDuty(array$input):string
    {
        $dutyKey=trim((string)($input['duty_key']??''));$employee=(int)($input['employee_id']??0);$assignedBy=(int)($input['assigned_by']??0);$acceptedBy=(int)($input['accepted_by']??0);$level=(string)($input['responsibility_level']??'primary');
        if($dutyKey===''||$employee<=0||$assignedBy<=0||$acceptedBy!==$employee||!in_array($level,['primary','coverage','secondary'],true))throw new InvalidArgumentException('Duty ownership requires an accepted employee and valid responsibility level.');
        $from=Support::timestamp($input['effective_from']??null);$to=!empty($input['effective_to'])?Support::timestamp($input['effective_to']):null;if($to&&$to<=$from)throw new InvalidArgumentException('Duty end must be after duty start.');
        $uuid=Support::uuid();$this->pdo->beginTransaction();try{
            if($level==='primary'){$close=$this->pdo->prepare("UPDATE epi_v2_duty_periods SET effective_to=? WHERE duty_key=? AND responsibility_level='primary' AND effective_to IS NULL AND effective_from<=?");$close->execute([$from->format('Y-m-d H:i:s'),$dutyKey,$from->format('Y-m-d H:i:s')]);}
            $stmt=$this->pdo->prepare('INSERT INTO epi_v2_duty_periods(duty_uuid,duty_key,employee_id,responsibility_level,effective_from,effective_to,assigned_by,accepted_by,accepted_at,source,shift_id,exception_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())');$stmt->execute([$uuid,$dutyKey,$employee,$level,$from->format('Y-m-d H:i:s'),$to?$to->format('Y-m-d H:i:s'):null,$assignedBy,$acceptedBy,Support::timestamp($input['accepted_at']??$from)->format('Y-m-d H:i:s'),$input['source']??'owner_assignment',$input['shift_id']??null,$input['exception_id']??null]);$this->pdo->commit();return$uuid;
        }catch(\Throwable$error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    public function assign(array $input): string
    {
        $module = Support::requireModule((string) ($input['module'] ?? ''));
        $reference = trim((string) ($input['object_reference'] ?? ''));
        $employeeId = (int) ($input['employee_id'] ?? 0);
        $assignedBy = (int) ($input['assigned_by'] ?? 0);
        $acceptedBy = (int) ($input['accepted_by'] ?? 0);
        if ($reference === '' || $employeeId <= 0 || $assignedBy <= 0 || $acceptedBy !== $employeeId) {
            throw new InvalidArgumentException('Ownership must identify an accepted employee assignment.');
        }
        $from = Support::timestamp($input['effective_from'] ?? null);
        $uuid = Support::uuid();
        $this->pdo->beginTransaction();
        try {
            $this->closeCurrent($module, $reference, $from, (string) ($input['transfer_reason'] ?? 'reassignment'));
            $stmt = $this->pdo->prepare(
                'INSERT INTO epi_v2_ownership_periods
                 (ownership_uuid,module,object_reference,employee_id,role_key,ownership_reason,effective_from,effective_to,
                  assigned_by,accepted_by,accepted_at,transfer_reason,source,shift_id,exception_id,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
            );
            $stmt->execute([
                $uuid,$module,$reference,$employeeId,$input['role_key'] ?? null,$input['ownership_reason'] ?? 'direct_assignment',
                $from->format('Y-m-d H:i:s'),null,$assignedBy,$acceptedBy,
                Support::timestamp($input['accepted_at'] ?? $from)->format('Y-m-d H:i:s'),
                $input['transfer_reason'] ?? null,$input['source'] ?? 'portal',$input['shift_id'] ?? null,$input['exception_id'] ?? null,
            ]);
            $this->pdo->commit();
            return $uuid;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function closeCurrent(string $module, string $reference, DateTimeImmutable $at, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE epi_v2_ownership_periods SET effective_to=?,transfer_reason=COALESCE(NULLIF(?,\'\'),transfer_reason)
             WHERE module=? AND object_reference=? AND effective_to IS NULL AND effective_from<=?'
        );
        $stmt->execute([$at->format('Y-m-d H:i:s'), trim($reason), $module, $reference, $at->format('Y-m-d H:i:s')]);
        if ($stmt->rowCount() > 1) throw new RuntimeException('Overlapping active ownership periods detected.');
    }

    public function initiateHandover(array $input): string
    {
        $outgoing=(int)($input['outgoing_employee_id']??0);$incoming=(int)($input['incoming_employee_id']??0);
        $initiatedBy=(int)($input['initiated_by']??0);$dutyKey=trim((string)($input['duty_key']??''));
        $reason=trim((string)($input['transfer_reason']??''));
        if($outgoing<=0||$incoming<=0||$incoming===$outgoing||$initiatedBy<=0||$dutyKey===''||$reason==='')throw new InvalidArgumentException('A handover requires two employees, a duty and a transfer reason.');
        $uuid=Support::uuid();$at=Support::timestamp($input['initiated_at']??null)->format('Y-m-d H:i:s');
        $stmt=$this->pdo->prepare('INSERT INTO epi_v2_handovers(handover_uuid,duty_key,outgoing_employee_id,incoming_employee_id,initiated_at,initiated_by,transfer_reason,open_orders_json,waiting_customers_json,pending_payments_json,pending_courier_actions_json,unresolved_followups_json,customer_promises_json,source,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        $stmt->execute([$uuid,$dutyKey,$outgoing,$incoming,$at,$initiatedBy,$reason,Support::json($input['open_orders']??[]),Support::json($input['waiting_customers']??[]),Support::json($input['pending_payments']??[]),Support::json($input['pending_courier_actions']??[]),Support::json($input['unresolved_followups']??[]),Support::json($input['customer_promises']??[]),$input['source']??'portal']);
        return$uuid;
    }

    public function acceptHandover(string $handoverUuid,int $employeeId,$acceptedAt=null): array
    {
        $at=Support::timestamp($acceptedAt);$this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("SELECT * FROM epi_v2_handovers WHERE handover_uuid=? AND status='pending' FOR UPDATE");$stmt->execute([$handoverUuid]);$handover=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$handover||(int)$handover['incoming_employee_id']!==$employeeId)throw new RuntimeException('Pending handover not found for this employee.');
            $update=$this->pdo->prepare("UPDATE epi_v2_handovers SET accepted_at=?,accepted_by=?,status='accepted' WHERE id=? AND status='pending'");$update->execute([$at->format('Y-m-d H:i:s'),$employeeId,(int)$handover['id']]);
            $close=$this->pdo->prepare('UPDATE epi_v2_duty_periods SET effective_to=? WHERE duty_key=? AND employee_id=? AND effective_to IS NULL');$close->execute([$at->format('Y-m-d H:i:s'),$handover['duty_key'],$handover['outgoing_employee_id']]);
            $dutyUuid=Support::uuid();$insert=$this->pdo->prepare("INSERT INTO epi_v2_duty_periods(duty_uuid,duty_key,employee_id,responsibility_level,effective_from,assigned_by,accepted_by,accepted_at,source,created_at) VALUES(?,?,?,'coverage',?,?,?,?,?,NOW())");
            $insert->execute([$dutyUuid,$handover['duty_key'],$employeeId,$at->format('Y-m-d H:i:s'),$handover['initiated_by'],$employeeId,$at->format('Y-m-d H:i:s'),'handover:'.$handoverUuid]);
            $this->transferHandoverObjects($handover,$employeeId,$at,$handoverUuid);
            $this->pdo->commit();return['handover_uuid'=>$handoverUuid,'duty_uuid'=>$dutyUuid,'effective_from'=>$at->format('Y-m-d H:i:s')];
        }catch(\Throwable$error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    private function transferHandoverObjects(array $handover,int $employeeId,DateTimeImmutable $at,string $handoverUuid):void
    {
        $groups=['open_orders_json'=>'Orders','waiting_customers_json'=>'Orders','pending_payments_json'=>'Orders','unresolved_followups_json'=>'Orders','customer_promises_json'=>'Orders','pending_courier_actions_json'=>'Courier'];
        $objects=[];
        foreach($groups as$column=>$module){$values=json_decode((string)($handover[$column]??'[]'),true);if(!is_array($values))continue;foreach($values as$value){$reference=is_array($value)?(string)($value['reference']??$value['id']??''):(string)$value;if(trim($reference)!=='')$objects[$module.'|'.trim($reference)]=[$module,trim($reference)];}}
        $insert=$this->pdo->prepare('INSERT INTO epi_v2_ownership_periods(ownership_uuid,module,object_reference,employee_id,role_key,ownership_reason,effective_from,assigned_by,accepted_by,accepted_at,transfer_reason,source,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        foreach($objects as[$module,$reference]){
            $this->closeCurrent($module,$reference,$at,(string)$handover['transfer_reason']);
            $insert->execute([Support::uuid(),$module,$reference,$employeeId,null,'accepted_handover',$at->format('Y-m-d H:i:s'),$handover['initiated_by'],$employeeId,$at->format('Y-m-d H:i:s'),$handover['transfer_reason'],'handover:'.$handoverUuid]);
        }
    }
}
