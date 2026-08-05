<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (!hash_equals('7be28658b67d478d84c0c9d497f5fae5', (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Not found']));
}

try {
    require_once __DIR__ . '/shared/database.php';
    $pdo = db();
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $from = '2026-07-01 00:00:00';
    $to = '2026-08-01 00:00:00';
    $tables = [
        'ops_employees' => null,
        'ops_orders' => 'created_at',
        'ops_order_stage_events' => 'occurred_at',
        'ops_activity_logs' => 'created_at',
        'kpi_status_events' => 'changed_at',
        'kpi_activity_events' => 'occurred_at',
        'ops_packing_tasks' => 'date_loaded',
        'ops_checklist_tasks' => 'created_at',
        'hambelela_waybills' => 'uploaded_at',
        'hambelela_waybill_sla_log' => 'logged_at',
        'ops_cash_book_entries' => 'transaction_date',
        'hambelela_cashbook_log' => 'created_at',
        'kpi_sessions' => 'login_at',
        'ops_error_logs' => 'logged_at',
        'notifications' => 'created_at',
        'epi_employee_evidence' => 'occurred_at',
        'epi_employee_activity' => 'occurred_at',
    ];
    $result = ['ok' => true, 'database' => $database, 'period' => [$from, $to], 'tables' => []];
    foreach ($tables as $table => $timeColumn) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');
        $exists->execute([$database, $table]);
        if (!(int) $exists->fetchColumn()) {
            $result['tables'][$table] = ['exists' => false];
            continue;
        }
        $columns = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position');
        $columns->execute([$database, $table]);
        $columnNames = array_map(static function (array $row): string { return (string) $row['column_name']; }, $columns->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $entry = ['exists' => true, 'columns' => $columnNames, 'total' => (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn()];
        if ($timeColumn !== null && in_array($timeColumn, $columnNames, true)) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $timeColumn . '`>=? AND `' . $timeColumn . '`<?');
            $count->execute([$from, $to]);
            $entry['july_count'] = (int) $count->fetchColumn();
            $range = $pdo->query('SELECT MIN(`' . $timeColumn . '`) first_record,MAX(`' . $timeColumn . '`) last_record FROM `' . $table . '`')->fetch(PDO::FETCH_ASSOC) ?: [];
            $entry['first_record'] = $range['first_record'] ?? null;
            $entry['last_record'] = $range['last_record'] ?? null;
        }
        $result['tables'][$table] = $entry;
    }
    $result['employees'] = $pdo->query("SELECT e.id,e.full_name,e.status,r.role_key,r.name role_name FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id ORDER BY e.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $result['ops_activity_by_actor'] = $pdo->query("SELECT employee_id,entity_type,action,COUNT(*) records,MIN(created_at) first_record,MAX(created_at) last_record FROM ops_activity_logs WHERE created_at>='2026-07-01 00:00:00' AND created_at<'2026-08-01 00:00:00' GROUP BY employee_id,entity_type,action ORDER BY employee_id,entity_type,action")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $result['status_events_by_actor'] = $pdo->query("SELECT changed_by,module,old_status,new_status,COUNT(*) records,MIN(changed_at) first_record,MAX(changed_at) last_record FROM kpi_status_events WHERE changed_at>='2026-07-01 00:00:00' AND changed_at<'2026-08-01 00:00:00' GROUP BY changed_by,module,old_status,new_status ORDER BY changed_by,module,old_status,new_status")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
