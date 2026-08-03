<?php

declare(strict_types=1);

/**
 * Read-only normalization of existing operational logs for KPI evidence.
 * Original rows are never modified. Duplicate actions recorded by more than one
 * logger are collapsed by record, actor, action/status and server timestamp.
 */

function kpi_event_section(string $module): string
{
    $key = strtolower(trim($module));
    $map = [
        'order' => 'orders', 'orders' => 'orders',
        'packing' => 'packing_list', 'packing_import' => 'packing_list', 'packing_task' => 'packing_list',
        'checklist_task' => 'tasks', 'task' => 'tasks',
        'website_update' => 'website_updates',
        'waybill' => 'courier_waybills', 'courier_waybill' => 'courier_waybills', 'courier_waybill_batch' => 'courier_waybills',
        'bookkeeping' => 'bookkeeping', 'cashbook_entry' => 'bookkeeping',
        'error_log' => 'error_log', 'error' => 'error_log',
        'session' => 'portal_activity', 'notification' => 'notifications',
    ];
    return $map[$key] ?? $key;
}

function kpi_event_metadata($raw): array
{
    if (is_array($raw)) return $raw;
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function kpi_event_meta_value(array $metadata, array $paths)
{
    foreach ($paths as $path) {
        $value = $metadata;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) { $value = null; break; }
            $value = $value[$part];
        }
        if ($value !== null && $value !== '') return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES);
    }
    return null;
}

function kpi_normalize_event(array $event): array
{
    $event += [
        'event_id' => '', 'section' => '', 'record_type' => '', 'record_id' => 0,
        'actor_user_id' => null, 'actor_name' => null, 'action' => '',
        'previous_status' => null, 'new_status' => null, 'occurred_at' => null,
        'source_log' => '', 'source_event_id' => null, 'metadata' => [],
        'evidence_quality' => 'exact_logged_event', 'related_reference' => null,
    ];
    $event['section'] = kpi_event_section((string) $event['section']);
    $event['record_id'] = (int) $event['record_id'];
    $event['actor_user_id'] = $event['actor_user_id'] === null ? null : (int) $event['actor_user_id'];
    $event['metadata'] = kpi_event_metadata($event['metadata']);
    return $event;
}

function kpi_event_dedupe_key(array $event): string
{
    return hash('sha256', implode('|', [
        $event['section'], $event['record_type'], (string) $event['record_id'],
        (string) ($event['actor_user_id'] ?? ''), $event['action'],
        (string) ($event['previous_status'] ?? ''), (string) ($event['new_status'] ?? ''),
        (string) $event['occurred_at'],
    ]));
}

function kpi_unified_events(string $fromSql, string $toSql, ?int $employeeId = null, ?string $section = null, ?string $recordType = null, ?int $recordId = null): array
{
    $events = [];
    $accept = static function (array $event) use (&$events, $employeeId, $section, $recordType, $recordId): void {
        $event = kpi_normalize_event($event);
        if ($employeeId && (int) ($event['actor_user_id'] ?? 0) !== $employeeId) return;
        if ($section && $event['section'] !== $section) return;
        if ($recordType && $event['record_type'] !== $recordType) return;
        if ($recordId && $event['record_id'] !== $recordId) return;
        $key = kpi_event_dedupe_key($event);
        if (!isset($events[$key])) $events[$key] = $event;
        elseif ($events[$key]['source_log'] !== 'kpi_activity_events' && $event['source_log'] === 'kpi_activity_events') $events[$key] = $event;
    };

    if (ops_table_exists('ops_activity_logs')) {
        foreach (ops_rows('SELECT l.id,l.employee_id,l.action,l.entity_type,l.entity_id,l.metadata,l.created_at,e.full_name actor_name FROM ops_activity_logs l LEFT JOIN ops_employees e ON e.id=l.employee_id WHERE l.created_at BETWEEN ? AND ? ORDER BY l.created_at,l.id', [$fromSql,$toSql]) as $row) {
            $metadata = kpi_event_metadata($row['metadata'] ?? null);
            $accept([
                'event_id'=>'ops_activity_logs:'.$row['id'], 'section'=>$row['entity_type'], 'record_type'=>(string)$row['entity_type'],
                'record_id'=>(int)$row['entity_id'], 'actor_user_id'=>$row['employee_id'], 'actor_name'=>$row['actor_name'],
                'action'=>(string)$row['action'], 'previous_status'=>kpi_event_meta_value($metadata,['previous_status','old_status','changes.status.from','changes.status.old']),
                'new_status'=>kpi_event_meta_value($metadata,['new_status','status','changes.status.to','changes.status.new']),
                'occurred_at'=>$row['created_at'], 'source_log'=>'ops_activity_logs', 'source_event_id'=>$row['id'], 'metadata'=>$metadata,
                'related_reference'=>kpi_event_meta_value($metadata,['order_number','order_reference','waybill_reference','task_name','item_name']),
            ]);
        }
    }
    if (ops_table_exists('kpi_status_events')) {
        foreach (ops_rows('SELECT s.id,s.module,s.record_id,s.old_status,s.new_status,s.changed_by,s.changed_at,e.full_name actor_name FROM kpi_status_events s LEFT JOIN ops_employees e ON e.id=s.changed_by WHERE s.changed_at BETWEEN ? AND ? ORDER BY s.changed_at,s.id', [$fromSql,$toSql]) as $row) {
            $accept(['event_id'=>'kpi_status_events:'.$row['id'],'section'=>$row['module'],'record_type'=>(string)$row['module'],'record_id'=>$row['record_id'],'actor_user_id'=>$row['changed_by'],'actor_name'=>$row['actor_name'],'action'=>'status_changed','previous_status'=>$row['old_status'],'new_status'=>$row['new_status'],'occurred_at'=>$row['changed_at'],'source_log'=>'kpi_status_events','source_event_id'=>$row['id']]);
        }
    }
    if (ops_table_exists('ops_order_stage_events')) {
        foreach (ops_rows('SELECT s.id,s.order_id,s.stage_key,s.employee_id,s.metadata,s.occurred_at,e.full_name actor_name FROM ops_order_stage_events s LEFT JOIN ops_employees e ON e.id=s.employee_id WHERE s.occurred_at BETWEEN ? AND ? ORDER BY s.occurred_at,s.id', [$fromSql,$toSql]) as $row) {
            $accept(['event_id'=>'ops_order_stage_events:'.$row['id'],'section'=>'orders','record_type'=>'order','record_id'=>$row['order_id'],'actor_user_id'=>$row['employee_id'],'actor_name'=>$row['actor_name'],'action'=>$row['stage_key'],'occurred_at'=>$row['occurred_at'],'source_log'=>'ops_order_stage_events','source_event_id'=>$row['id'],'metadata'=>$row['metadata']]);
        }
    }
    if (ops_table_exists('hambelela_cashbook_log')) {
        foreach (ops_rows('SELECT id,entry_id,action,field,old_value,new_value,description,user_id,user_name,created_at FROM hambelela_cashbook_log WHERE created_at BETWEEN ? AND ? ORDER BY created_at,id', [$fromSql,$toSql]) as $row) {
            $accept(['event_id'=>'hambelela_cashbook_log:'.$row['id'],'section'=>'bookkeeping','record_type'=>'bookkeeping','record_id'=>$row['entry_id'],'actor_user_id'=>$row['user_id'],'actor_name'=>$row['user_name'],'action'=>$row['action'],'previous_status'=>$row['old_value'],'new_status'=>$row['new_value'],'occurred_at'=>$row['created_at'],'source_log'=>'hambelela_cashbook_log','source_event_id'=>$row['id'],'metadata'=>['field'=>$row['field'],'description'=>$row['description']]]);
        }
    }
    if (ops_table_exists('hambelela_waybill_sla_log')) {
        foreach (ops_rows('SELECT l.id,l.waybill_id,l.user_id,l.due_by,l.sent_at,l.minutes_late,l.logged_at,e.full_name actor_name FROM hambelela_waybill_sla_log l LEFT JOIN ops_employees e ON e.id=l.user_id WHERE l.logged_at BETWEEN ? AND ? ORDER BY l.logged_at,l.id', [$fromSql,$toSql]) as $row) {
            $accept(['event_id'=>'hambelela_waybill_sla_log:'.$row['id'],'section'=>'courier_waybills','record_type'=>'waybill','record_id'=>$row['waybill_id'],'actor_user_id'=>$row['user_id'],'actor_name'=>$row['actor_name'],'action'=>$row['sent_at']?'waybill_sent_sla':'waybill_sla_recorded','occurred_at'=>$row['sent_at']?:$row['logged_at'],'source_log'=>'hambelela_waybill_sla_log','source_event_id'=>$row['id'],'metadata'=>['due_by'=>$row['due_by'],'minutes_late'=>$row['minutes_late']]]);
        }
    }
    if (ops_table_exists('kpi_activity_events')) {
        foreach (ops_rows('SELECT id,portal_section,record_type,record_id,employee_id,employee_name,action_key,previous_status,new_status,occurred_at,related_reference,metadata_json FROM kpi_activity_events WHERE occurred_at BETWEEN ? AND ? ORDER BY occurred_at,id', [$fromSql,$toSql]) as $row) {
            $accept(['event_id'=>'kpi_activity_events:'.$row['id'],'section'=>$row['portal_section'],'record_type'=>$row['record_type'],'record_id'=>$row['record_id'],'actor_user_id'=>$row['employee_id'],'actor_name'=>$row['employee_name'],'action'=>$row['action_key'],'previous_status'=>$row['previous_status'],'new_status'=>$row['new_status'],'occurred_at'=>$row['occurred_at'],'source_log'=>'kpi_activity_events','source_event_id'=>$row['id'],'metadata'=>$row['metadata_json'],'related_reference'=>$row['related_reference']]);
        }
    }
    if (ops_table_exists('kpi_sessions')) {
        foreach (ops_rows('SELECT s.id,s.user_id,s.login_at,s.last_seen_at,s.logout_at,e.full_name actor_name FROM kpi_sessions s LEFT JOIN ops_employees e ON e.id=s.user_id WHERE s.login_at BETWEEN ? AND ? ORDER BY s.login_at,s.id', [$fromSql,$toSql]) as $row) {
            $accept(['event_id'=>'kpi_sessions:'.$row['id'].':login','section'=>'portal_activity','record_type'=>'session','record_id'=>$row['id'],'actor_user_id'=>$row['user_id'],'actor_name'=>$row['actor_name'],'action'=>'login','occurred_at'=>$row['login_at'],'source_log'=>'kpi_sessions','source_event_id'=>$row['id'],'metadata'=>['last_seen_at'=>$row['last_seen_at'],'logout_at'=>$row['logout_at']]]);
            if (!empty($row['logout_at'])) $accept(['event_id'=>'kpi_sessions:'.$row['id'].':logout','section'=>'portal_activity','record_type'=>'session','record_id'=>$row['id'],'actor_user_id'=>$row['user_id'],'actor_name'=>$row['actor_name'],'action'=>'logout','occurred_at'=>$row['logout_at'],'source_log'=>'kpi_sessions','source_event_id'=>$row['id'],'metadata'=>[]]);
        }
    }
    $rows = array_values($events);
    usort($rows, static function(array $a,array $b): int { return strcmp((string)$a['occurred_at'],(string)$b['occurred_at']) ?: strcmp((string)$a['event_id'],(string)$b['event_id']); });
    return $rows;
}

function kpi_activity_log_audit(): array
{
    $sources = [
        'ops_activity_logs'=>['time'=>'created_at','actor'=>'employee_id','record'=>'entity_id','action'=>'action','status'=>'metadata','metadata'=>'metadata'],
        'kpi_status_events'=>['time'=>'changed_at','actor'=>'changed_by','record'=>'record_id','action'=>'module','status'=>'old_status,new_status','metadata'=>''],
        'ops_order_stage_events'=>['time'=>'occurred_at','actor'=>'employee_id','record'=>'order_id','action'=>'stage_key','status'=>'','metadata'=>'metadata'],
        'hambelela_cashbook_log'=>['time'=>'created_at','actor'=>'user_id','record'=>'entry_id','action'=>'action','status'=>'old_value,new_value','metadata'=>'field,description'],
        'hambelela_waybill_sla_log'=>['time'=>'logged_at','actor'=>'user_id','record'=>'waybill_id','action'=>'sent_at','status'=>'','metadata'=>'due_by,minutes_late'],
        'ops_error_logs'=>['time'=>'logged_at','actor'=>'logged_by','record'=>'id','action'=>'status','status'=>'status','metadata'=>'responsible_employee_id,packing_task_id,order_reference'],
        'kpi_sessions'=>['time'=>'login_at','actor'=>'user_id','record'=>'id','action'=>'login_at,logout_at','status'=>'','metadata'=>'last_seen_at'],
        'notifications'=>['time'=>'created_at','actor'=>'created_by','record'=>'related_id','action'=>'module','status'=>'','metadata'=>'related_type,priority'],
        'kpi_activity_events'=>['time'=>'occurred_at','actor'=>'employee_id','record'=>'record_id','action'=>'action_key','status'=>'previous_status,new_status','metadata'=>'metadata_json'],
    ];
    $audit=[];
    foreach($sources as$table=>$fields){
        $row=['table'=>$table,'available'=>ops_table_exists($table),'first_record'=>null,'last_record'=>null,'record_count'=>0]+$fields;
        if($row['available']){
            $stats=ops_rows('SELECT COUNT(*) record_count,MIN('.$fields['time'].') first_record,MAX('.$fields['time'].') last_record FROM '.$table)[0]??[];
            $row['record_count']=(int)($stats['record_count']??0);$row['first_record']=$stats['first_record']??null;$row['last_record']=$stats['last_record']??null;
        }
        $audit[]=$row;
    }
    return $audit;
}

function kpi_activity_coverage_by_section(): array
{
    $coverage=[];
    $record=static function(string $section,$date)use(&$coverage):void{
        if(!$date)return;$day=substr((string)$date,0,10);
        if(!isset($coverage[$section])||$day<$coverage[$section])$coverage[$section]=$day;
    };
    if(ops_table_exists('ops_activity_logs'))foreach(ops_rows('SELECT entity_type,MIN(created_at) first_record FROM ops_activity_logs GROUP BY entity_type')as$row)$record(kpi_event_section((string)$row['entity_type']),$row['first_record']);
    if(ops_table_exists('kpi_status_events'))foreach(ops_rows('SELECT module,MIN(changed_at) first_record FROM kpi_status_events GROUP BY module')as$row)$record(kpi_event_section((string)$row['module']),$row['first_record']);
    if(ops_table_exists('ops_order_stage_events'))$record('orders',(ops_rows('SELECT MIN(occurred_at) first_record FROM ops_order_stage_events')[0]['first_record']??null));
    if(ops_table_exists('hambelela_cashbook_log'))$record('bookkeeping',(ops_rows('SELECT MIN(created_at) first_record FROM hambelela_cashbook_log')[0]['first_record']??null));
    if(ops_table_exists('hambelela_waybill_sla_log'))$record('courier_waybills',(ops_rows('SELECT MIN(logged_at) first_record FROM hambelela_waybill_sla_log')[0]['first_record']??null));
    if(ops_table_exists('ops_error_logs'))$record('error_log',(ops_rows('SELECT MIN(logged_at) first_record FROM ops_error_logs')[0]['first_record']??null));
    if(ops_table_exists('kpi_sessions'))$record('portal_activity',(ops_rows('SELECT MIN(login_at) first_record FROM kpi_sessions')[0]['first_record']??null));
    if(ops_table_exists('kpi_activity_events'))foreach(ops_rows('SELECT portal_section,MIN(occurred_at) first_record FROM kpi_activity_events GROUP BY portal_section')as$row)$record(kpi_event_section((string)$row['portal_section']),$row['first_record']);
    ksort($coverage);
    return $coverage;
}
