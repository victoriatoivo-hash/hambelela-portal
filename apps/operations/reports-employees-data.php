<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/kpi-reporting.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');

try {
    if (!ops_database_ready()) throw new RuntimeException('The operations database is unavailable.');

    $zone = new DateTimeZone('Africa/Windhoek');
    $settings = [];
    foreach (ops_rows('SELECT setting_key, setting_value FROM kpi_settings') as $row) {
        $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    }

    $requestedPeriod = strtolower(trim((string) ($_GET['period'] ?? 'since_trusted')));
    if ($requestedPeriod === 'custom' && (trim((string) ($_GET['date_from'] ?? '')) === '' || trim((string) ($_GET['date_to'] ?? '')) === '')) {
        kpi_send_json([
            'ok' => false,
            'success' => false,
            'data' => null,
            'message' => 'Choose both a From and To date.',
            'error_code' => 'KPI_CUSTOM_DATES_REQUIRED',
        ], 422);
    }

    $periodInput = $_GET;
    $periodInput['period'] = $requestedPeriod;
    $periodInput['trusted_start_date'] = $settings['trusted_performance_start_date'] ?? '2026-07-10';
    try {
        $resolvedPeriod = kpi_resolve_reporting_period($periodInput);
    } catch (Throwable $periodError) {
        kpi_send_json([
            'ok' => false,
            'success' => false,
            'data' => null,
            'message' => $periodError->getMessage(),
            'error_code' => 'KPI_REPORTING_PERIOD_INVALID',
        ], 422);
    }
    $from = $resolvedPeriod['from'];
    $to = $resolvedPeriod['to'];
    $dataStart = new DateTimeImmutable($settings['trusted_performance_start_date'] ?? '2026-07-10', $zone);
    $adoption = new DateTimeImmutable($settings['adoption_date'] ?? '2026-07-14', $zone);
    $effective = $from < $dataStart ? $dataStart : $from;
    $fromSql = $effective->format('Y-m-d 00:00:00');
    $toSql = $to->format('Y-m-d 23:59:59');

    $employees = ops_rows(
        "SELECT e.id, e.full_name, r.name role_name, r.role_key
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         WHERE " . kpi_performance_employee_predicate('e', 'r') . "
         ORDER BY r.role_key, e.full_name"
    );

    $coverage = [];
    $loadSection = static function (string $key, string $source, callable $loader) use (&$coverage): array {
        try {
            $rows = $loader();
            $coverage[$key] = ['status' => 'available', 'source_log' => $source, 'message' => null];
            return $rows;
        } catch (Throwable $error) {
            error_log(date(DATE_ATOM) . ' employee index ' . $key . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
            $coverage[$key] = ['status' => 'unavailable', 'source_log' => $source, 'message' => 'Section data could not be loaded.'];
            return [];
        }
    };

    $sessions = $loadSection('portal_activity', 'kpi_sessions', static fn(): array => ops_rows(
        'SELECT user_id employee_id, SUM(TIMESTAMPDIFF(MINUTE, login_at, COALESCE(logout_at, last_seen_at))) / 60 hours FROM kpi_sessions WHERE login_at BETWEEN ? AND ? GROUP BY user_id',
        [$fromSql, $toSql]
    ));
    $packing = $loadSection('packing', 'ops_packing_tasks', static fn(): array => ops_rows(
        "SELECT assigned_employee_id employee_id, COUNT(*) items, COALESCE(SUM(workload_points), 0) points, SUM(packing_status NOT IN ('done','website','packed_label_needed','done_needs_label','label_created')) open_items FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY assigned_employee_id",
        [$fromSql, $toSql]
    ));
    $orders = $loadSection('orders', 'ops_orders', static fn(): array => ops_rows(
        "SELECT assigned_packer_id employee_id, COUNT(DISTINCT id) orders_done FROM ops_orders WHERE assigned_packer_id IS NOT NULL AND status IN ('completed','packed','verified') AND completed_at BETWEEN ? AND ? GROUP BY assigned_packer_id",
        [$fromSql, $toSql]
    ));
    $website = $loadSection('website_updates', 'ops_packing_tasks', static fn(): array => ops_rows(
        'SELECT frontdesk_website_updated_by employee_id, COUNT(DISTINCT id) updates_done FROM ops_packing_tasks WHERE frontdesk_website_updated = 1 AND frontdesk_website_updated_at BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY frontdesk_website_updated_by',
        [$fromSql, $toSql]
    ));
    $presence = $loadSection('presence', 'ops_board_presence', static fn(): array => ops_rows(
        'SELECT employee_id FROM ops_board_presence WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
    ));
    $spark = $loadSection('packing_trend', 'ops_packing_tasks', static fn(): array => ops_rows(
        'SELECT assigned_employee_id employee_id, DATE(date_completed) day, COALESCE(SUM(workload_points), 0) points FROM ops_packing_tasks WHERE date_completed >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND deleted_at IS NULL GROUP BY assigned_employee_id, DATE(date_completed) ORDER BY day'
    ));

    $indexRows = static function (array $rows): array {
        $indexed = [];
        foreach ($rows as $row) $indexed[(int) ($row['employee_id'] ?? 0)] = $row;
        return $indexed;
    };
    $sessionMap = $indexRows($sessions);
    $packingMap = $indexRows($packing);
    $orderMap = $indexRows($orders);
    $websiteMap = $indexRows($website);
    $onlineMap = array_fill_keys(array_map(static fn(array $row): int => (int) ($row['employee_id'] ?? 0), $presence), true);

    foreach ($employees as &$employee) {
        $id = (int) $employee['id'];
        $employee['hours'] = $coverage['portal_activity']['status'] === 'available' ? (float) ($sessionMap[$id]['hours'] ?? 0) : null;
        $employee['items'] = $coverage['packing']['status'] === 'available' ? (int) ($packingMap[$id]['items'] ?? 0) : null;
        $employee['points'] = $coverage['packing']['status'] === 'available' ? (float) ($packingMap[$id]['points'] ?? 0) : null;
        $employee['open_items'] = $coverage['packing']['status'] === 'available' ? (int) ($packingMap[$id]['open_items'] ?? 0) : null;
        $employee['orders_done'] = $coverage['orders']['status'] === 'available' ? (int) ($orderMap[$id]['orders_done'] ?? 0) : null;
        $employee['updates_done'] = $coverage['website_updates']['status'] === 'available' ? (int) ($websiteMap[$id]['updates_done'] ?? 0) : null;
        $employee['online'] = $coverage['presence']['status'] === 'available' ? isset($onlineMap[$id]) : null;
    }
    unset($employee);

    $periodResponse = kpi_period_response($resolvedPeriod, $adoption, $effective);
    $data = ['period' => $periodResponse, 'employees' => $employees, 'spark' => $spark, 'coverage' => $coverage];
    kpi_send_json([
        'ok' => true,
        'success' => true,
        'data' => $data,
        'message' => null,
        'period' => $periodResponse,
        'employees' => $employees,
        'spark' => $spark,
        'coverage' => $coverage,
        'scores_disabled' => true,
        'last_refreshed_at' => (new DateTimeImmutable('now', $zone))->format(DATE_ATOM),
    ]);
} catch (Throwable $error) {
    error_log(date(DATE_ATOM) . ' employee index: ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
    kpi_send_json(['ok' => false, 'success' => false, 'data' => null, 'message' => 'Employee performance is temporarily unavailable.', 'error_code' => 'KPI_EMPLOYEE_INDEX_FAILED'], 500);
}
