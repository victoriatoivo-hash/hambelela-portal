<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateTimeImmutable;
use PDO;
use Throwable;

/** Fail-safe adapter from the existing Packing List activity stream into EPI. */
final class PackingActivityBridge
{
    private static $available;

    public static function record(PDO $pdo, string $legacyAction, int $itemId, array $input = []): void
    {
        if ($itemId <= 0) return;
        try {
            if (self::$available === null) {
                Performance::configure($pdo);
                $flag = $pdo->query("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='packing_module_enabled' LIMIT 1")->fetchColumn();
                self::$available = Performance::enabled() && ($flag === false || in_array(strtolower(trim((string)$flag)), ['1','true','yes','on'], true));
            }
            if (!self::$available) return;

            $item = self::item($pdo, $itemId);
            if (!$item) return;
            $actor = self::actor($pdo, isset($input['employee_id']) ? (int)$input['employee_id'] : null);
            $at = Support::timestamp($input['changed_at'] ?? $input['occurred_at'] ?? null);
            $reference = 'PACK-' . $itemId;
            $field = (string)($input['field'] ?? '');
            $before = self::normaliseStatus((string)($input['previous_status'] ?? ($field === 'packing_status' ? ($input['previous_value'] ?? '') : '')));
            $after = self::normaliseStatus((string)($input['status'] ?? ($field === 'packing_status' ? ($input['new_value'] ?? $item['packing_status']) : '')));
            $action = self::action($legacyAction, $field, $before, $after, (string)($input['new_value'] ?? ''));
            $loadedAt = self::date($item['date_loaded'] ?? $item['created_at'] ?? null);
            $startedAt = self::date($item['date_started'] ?? null);
            $completedAt = self::date($item['date_completed'] ?? null);
            if ($action === 'packing_item_started' && !$startedAt) $startedAt = $at;
            if ($action === 'packing_item_completed' && !$completedAt) $completedAt = $at;
            $durations = [
                'loaded_to_started_minutes' => $loadedAt && $startedAt ? Performance::businessMinutes($loadedAt, $startedAt) : null,
                'started_to_done_minutes' => $startedAt && $completedAt ? Performance::businessMinutes($startedAt, $completedAt) : null,
                'loaded_to_completed_minutes' => $loadedAt && $completedAt ? Performance::businessMinutes($loadedAt, $completedAt) : null,
            ];
            $quantity = self::quantity((string)($item['quantity_planned'] ?? ''), (string)($item['quantity_packed'] ?? ''));
            $workload = self::workload($pdo, (string)($item['item_name'] ?? ''), (string)($item['received_weight'] ?? ''), (string)($item['quantity_planned'] ?? ''));
            $metadata = array_merge($input, $durations, $quantity, $workload, [
                'legacy_action'=>$legacyAction, 'packing_item_id'=>$itemId, 'item_name'=>(string)($item['item_name'] ?? ''),
                'assigned_employee_id'=>isset($item['assigned_employee_id']) ? (int)$item['assigned_employee_id'] : null,
                'priority'=>(string)($item['priority'] ?? ''), 'date_loaded'=>$loadedAt ? $loadedAt->format('Y-m-d H:i:s') : null,
                'date_started'=>$startedAt ? $startedAt->format('Y-m-d H:i:s') : null,
                'date_completed'=>$completedAt ? $completedAt->format('Y-m-d H:i:s') : null,
                'packing_status'=>(string)($item['packing_status'] ?? ''), 'notes'=>(string)($item['notes'] ?? ''),
                'workload_points'=>isset($item['workload_points_override']) && $item['workload_points_override'] !== null ? (float)$item['workload_points_override'] : (float)($item['workload_points'] ?? 0),
                'packer_website_confirmed'=>(bool)($item['packing_website_confirmed'] ?? false),
                'packer_website_confirmed_at'=>$item['packing_website_completed_at'] ?? null,
                'packer_website_confirmed_by'=>$item['packing_website_completed_by'] ?? null,
                'website_confirmation_type'=>'Packer Website Update Confirmation',
            ]);
            $dedupe = Support::dedupe(['packing-activity',$itemId,$legacyAction,$actor['id'] ?? '',$at->format('Y-m-d H:i:s'),$input]);
            Performance::recordActivity([
                'module'=>'Packing List','reference_number'=>$reference,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],
                'department'=>$actor['department'],'activity_type'=>$action,'description'=>self::description($action,$item),
                'activity_source'=>'packing_activity_log:'.$legacyAction,'timestamp'=>$at,'manual'=>true,
                'deduplication_key'=>$dedupe,'metadata'=>$metadata,
            ]);
            $uuid = Performance::recordEvidence([
                'module'=>'Packing List','reference_number'=>$reference,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],
                'department'=>$actor['department'],'action'=>$action,'action_description'=>self::description($action,$item),
                'previous_value'=>$input['previous_value'] ?? null,'new_value'=>$input['new_value'] ?? $input['value'] ?? null,
                'status_before'=>$before ?: null,'status_after'=>$after ?: null,'priority'=>$item['priority'] ?? null,
                'timestamp'=>$at,'working_minutes'=>self::durationFor($action,$durations),'manual'=>true,
                'activity_source'=>'packing_activity_log:'.$legacyAction,
                'deduplication_key'=>Support::dedupe(['packing-evidence',$dedupe]),'metadata'=>$metadata,
            ]);
            self::ownership($reference,$item,$actor,$action,$at);
            self::candidates($pdo,$reference,$actor,$action,$at,$metadata,$uuid);
        } catch (Throwable $error) {
            self::logFailure($error,$legacyAction,$itemId);
        }
    }

    private static function item(PDO $pdo, int $id): ?array
    {
        $stmt=$pdo->prepare('SELECT * FROM ops_packing_tasks WHERE id=? LIMIT 1');$stmt->execute([$id]);return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function actor(PDO $pdo, ?int $id): array
    {
        $id=$id ?: (function_exists('ops_current_employee_id') ? (int)ops_current_employee_id() : 0);
        $fallback=function_exists('current_user') ? (string)(current_user()['name'] ?? '') : '';
        if ($id<=0) return ['id'=>null,'name'=>$fallback ?: 'System','department'=>'Packing'];
        try {$s=$pdo->prepare("SELECT e.full_name,COALESCE(r.name,'Packing') department FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?");$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC) ?: [];} catch(Throwable $e){$r=[];}
        return ['id'=>$id,'name'=>(string)($r['full_name'] ?? ($fallback ?: 'Employee '.$id)),'department'=>(string)($r['department'] ?? 'Packing')];
    }

    private static function action(string $legacy, string $field, string $before, string $after, string $value): string
    {
        if ($field==='packing_status') {
            if (self::done($after)) return 'packing_item_completed';
            if (self::done($before) && !self::done($after)) return 'packing_item_reopened';
            if (in_array($after,['in_progress','packing'],true) && !in_array($before,['in_progress','packing'],true)) return 'packing_item_started';
            return 'packing_status_changed';
        }
        if ($field==='assigned_employee_id') return 'packing_assignment_changed';
        if ($field==='quantity_packed') return 'packing_quantity_recorded';
        if ($field==='quantity_planned') return 'packing_quantity_required_changed';
        if ($field==='packing_website_confirmed') return in_array(strtolower($value),['1','true','yes'],true) ? 'packer_website_confirmation_added' : 'packer_website_confirmation_removed';
        if ($field==='notes') return 'packing_note_updated';
        if ($field==='priority') return 'packing_priority_changed';
        return $legacy;
    }

    private static function normaliseStatus(string $status): string
    {
        $status=strtolower(trim(str_replace(['–','—','-'], '_', $status)));return preg_replace('/[^a-z0-9]+/','_',$status) ?: '';
    }
    private static function done(string $status): bool {return in_array($status,['done','website','label_created','packed_label_needed','done_needs_label','done_needs_correction'],true);}
    private static function date($value): ?DateTimeImmutable {return trim((string)$value)!=='' ? Support::timestamp($value) : null;}
    private static function durationFor(string $action,array $d): ?float {return $action==='packing_item_started'?$d['loaded_to_started_minutes']:($action==='packing_item_completed'?$d['loaded_to_completed_minutes']:null);}

    private static function quantity(string $requiredRaw,string $packedRaw): array
    {
        $required=self::number($requiredRaw);$packed=self::number($packedRaw);$variance=$required!==null&&$packed!==null?$packed-$required:null;
        return ['required_quantity_raw'=>$requiredRaw,'packed_quantity_raw'=>$packedRaw,'required_quantity'=>$required,'packed_quantity'=>$packed,
            'quantity_variance'=>$variance,'quantity_class'=>$variance===null?'invalid_or_descriptive':(abs($variance)<0.000001?'exact':($variance>0?'overpacked':'underpacked'))];
    }
    private static function number(string $value): ?float {return preg_match('/^\s*(-?\d+(?:\.\d+)?)/',$value,$m)?(float)$m[1]:null;}

    private static function workload(PDO $pdo,string $name,string $variation,string $quantity): array
    {
        $source=strtolower($name.' '.$variation.' '.$quantity);$base=null;$unit=null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|g|ml|l)\b/i',$source,$m)) {$base=(float)$m[1];$unit=strtolower($m[2]);if($unit==='kg'){$base*=1000;$unit='g';}elseif($unit==='l'){$base*=1000;$unit='ml';}}
        $state='other';if($unit==='ml')$state='liquid';elseif(preg_match('/\b(powder)\b/',$source))$state='powder';elseif(preg_match('/\b(butter)\b/',$source))$state='butter';elseif(preg_match('/\b(herb|tea|leaf|leaves)\b/',$source))$state='herb';elseif($unit==='g')$state='solid';
        $threshold=['medium_volume'=>500,'high_volume'=>1000,'heavy_grams'=>10000,'very_heavy_grams'=>25000];
        try {$raw=$pdo->query("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='packing_workload_thresholds'")->fetchColumn();$decoded=json_decode((string)$raw,true);if(is_array($decoded))$threshold=array_merge($threshold,$decoded);}catch(Throwable $e){}
        $size='unknown';if($base!==null){$size=$base>=(float)$threshold['high_volume']?'high_volume':($base>=(float)$threshold['medium_volume']?'medium_volume':'low_volume');if($unit==='g'&&$base>=(float)$threshold['very_heavy_grams'])$size='very_heavy';elseif($unit==='g'&&$base>=(float)$threshold['heavy_grams'])$size='heavy';}
        return ['product_state'=>$state,'normalized_amount'=>$base,'normalized_unit'=>$unit,'workload_class'=>$size];
    }

    private static function ownership(string $reference,array $item,array $actor,string $action,DateTimeImmutable $at): void
    {
        if(!in_array($action,['packing_assignment_changed','packing_item_completed','packer_website_confirmation_added'],true))return;
        Performance::recordOwnership(['module'=>'Packing List','reference_number'=>$reference,
            'current_owner_id'=>$item['assigned_employee_id'] ?? null,'current_owner_name'=>null,
            'completed_by_id'=>$action==='packing_item_completed'?$actor['id']:null,'completed_by_name'=>$action==='packing_item_completed'?$actor['name']:null,
            'verified_by_id'=>$action==='packer_website_confirmation_added'?$actor['id']:null,'verified_by_name'=>$action==='packer_website_confirmation_added'?$actor['name']:null,
            'change_reason'=>$action,'changed_by'=>$actor['id'],'effective_at'=>$at]);
    }

    private static function candidates(PDO $pdo,string $reference,array $actor,string $action,DateTimeImmutable $at,array $m,?string $source): void
    {
        $rules=[];
        if($action==='packing_item_reopened')$rules[]=['deduction','item_reopened'];
        if($action==='packer_website_confirmation_removed')$rules[]=['deduction','website_confirmation_removed'];
        if($action==='packing_quantity_recorded'&&$m['quantity_class']==='underpacked')$rules[]=['deduction','quantity_underpacked'];
        if($action==='packing_quantity_recorded'&&$m['quantity_class']==='overpacked'&&trim((string)$m['notes'])==='')$rules[]=['deduction','quantity_overpacked_without_approval'];
        if($action==='packing_item_completed'&&$m['quantity_class']==='exact')$rules[]=['bonus','first_time_right_quantity'];
        if($action==='packer_website_confirmation_added')$rules[]=['bonus','website_confirmation_recorded'];
        $startLimits=self::jsonSetting($pdo,'packing_priority_start_minutes',['urgent'=>30,'top_critical'=>30,'high'=>60,'important'=>90,'normal'=>240,'medium'=>240,'low'=>480]);
        $completionLimits=self::jsonSetting($pdo,'packing_completion_minutes',['urgent'=>120,'top_critical'=>120,'high'=>240,'important'=>360,'normal'=>960,'medium'=>960,'low'=>1440]);
        $priority=strtolower((string)($m['priority']??'normal'));
        if($action==='packing_item_started'&&is_numeric($m['loaded_to_started_minutes']??null)&&(float)$m['loaded_to_started_minutes']>(float)($startLimits[$priority]??$startLimits['normal']))$rules[]=['deduction','packing_started_late'];
        if($action==='packing_item_completed'&&is_numeric($m['loaded_to_completed_minutes']??null)&&(float)$m['loaded_to_completed_minutes']>(float)($completionLimits[$priority]??$completionLimits['normal']))$rules[]=['deduction','packing_completed_late'];
        foreach($rules as $rule) Performance::recordEvidence(['module'=>'Packing List','reference_number'=>$reference,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],'department'=>$actor['department'],
            'action'=>$rule[0].'_candidate_'.$rule[1],'action_description'=>ucwords(str_replace('_',' ',$rule[0].' candidate '.$rule[1])),'timestamp'=>$at,'manual'=>false,
            'activity_source'=>'packing_epi_candidate_engine','deduplication_key'=>Support::dedupe(['packing-candidate',$reference,$rule[1],$at->format('Y-m-d H:i:s')]),
            'metadata'=>array_merge($m,['candidate_only'=>true,'review_status'=>'pending_owner_review','source_evidence_uuid'=>$source])]);
    }

    private static function jsonSetting(PDO $pdo,string $key,array $fallback): array
    {
        try{$s=$pdo->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=? LIMIT 1');$s->execute([$key]);$decoded=json_decode((string)$s->fetchColumn(),true);return is_array($decoded)?array_merge($fallback,$decoded):$fallback;}catch(Throwable $e){return $fallback;}
    }

    private static function description(string $action,array $item): string {return ucwords(str_replace('_',' ',$action)).' for '.((string)($item['item_name'] ?? 'packing item'));}
    private static function logFailure(Throwable $e,string $action,int $id): void {$dir=defined('BASE_PATH')?BASE_PATH.'/storage/logs':sys_get_temp_dir();if(!is_dir($dir))@mkdir($dir,0775,true);@file_put_contents($dir.'/epi-packing.log','['.date('c').'] '.$action.' packing '.$id.': '.$e->getMessage().PHP_EOL,FILE_APPEND);}
}
