<?php
declare(strict_types=1);

require_once __DIR__ . '/operations.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$viewerId = (int) ($user['id'] ?? 0);
$owner = user_has_role('owner_admin');
if ($viewerId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}
if (!$owner) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Owner access required.']);
    exit;
}

function epiDashboardPercent($value): ?float
{
    return $value === null ? null : round(((int) $value) / 100, 2);
}

function epiDashboardPublicScore(array $score, bool $owner): array
{
    $events = [];
    foreach (($score['events'] ?? []) as $event) {
        if (!$owner && ($event['confirmation_status'] ?? '') !== 'confirmed') continue;
        $events[] = [
            'id' => (int) $event['id'],
            'category' => (string) $event['category_key'],
            'kind' => (string) $event['event_kind'],
            'impact' => epiDashboardPercent($event['final_impact_hundredths']),
            'status' => (string) $event['confirmation_status'],
            'automatic_status' => (string) ($event['automatic_status'] ?? 'needs_review'),
            'confidence' => (string) ($event['confidence_level'] ?? 'insufficient'),
            'evidence_uuid' => (string) ($event['evidence_uuid'] ?? ''),
            'module' => (string) ($event['evidence_module'] ?? ''),
            'reference' => (string) ($event['reference_number'] ?? ''),
            'rule' => (string) ($event['rule_name'] ?? ''),
            'description' => (string) ($event['action_description'] ?? ''),
            'expected_result' => (string) ($event['expected_result'] ?? ''),
            'actual_result' => (string) ($event['actual_result'] ?? ''),
            'occurred_at' => (string) ($event['evidence_occurred_at'] ?? $event['created_at']),
            'note' => $owner ? (string) ($event['reviewer_note'] ?? '') : '',
            'created_at' => (string) $event['created_at'],
        ];
    }
    $categories = [];
    foreach (($score['categories'] ?? []) as $category) {
        $categories[] = [
            'key' => (string) $category['category_key'],
            'name' => (string) $category['category_name'],
            'weight' => epiDashboardPercent($category['weight_hundredths']),
            'score' => epiDashboardPercent($category['official_score_hundredths'] ?? null),
            'calculation_status' => (string) ($category['calculation_status'] ?? 'legacy'),
            'contribution' => epiDashboardPercent($category['weighted_contribution_hundredths']),
            'deductions' => epiDashboardPercent($category['deduction_hundredths']),
            'positive' => epiDashboardPercent($category['positive_hundredths']),
            'event_count' => (int) $category['event_count'],
        ];
    }
    return [
        'employee_id' => (int) $score['employee_id'],
        'employee_name' => (string) $score['employee_name'],
        'role_key' => (string) ($score['role_key'] ?? ''),
        'department_key' => (string) ($score['department_key'] ?? ''),
        'period_start' => (string) $score['period_start'],
        'period_end' => (string) $score['period_end'],
        'opening' => epiDashboardPercent($score['opening_hundredths']),
        'positive' => epiDashboardPercent($score['positive_hundredths']),
        'deductions' => epiDashboardPercent($score['deduction_hundredths']),
        'score' => epiDashboardPercent($score['official_score_hundredths'] ?? null),
        'performance_level' => (string) ($score['official_performance_level'] ?? 'Not Available'),
        'result_type' => (string) ($score['result_type'] ?? 'legacy'),
        'status' => (string) $score['score_status'],
        'locked' => (int) $score['locked'] === 1,
        'confidence' => (string) $score['confidence_label'],
        'completeness' => epiDashboardPercent($score['data_completeness_hundredths']),
        'evidence_count' => (int) $score['evidence_count'],
        'pending_review_count' => (int) $score['pending_review_count'],
        'confirmed_deduction_count' => (int) $score['confirmed_deduction_count'],
        'positive_evidence_count' => (int) $score['positive_evidence_count'],
        'categories' => $categories,
        'events' => $events,
    ];
}

try {
    $pdo = db();
    // Each HTTP request has isolated PHP state; configure the shared EPI
    // facade in this API request before reading its feature flag.
    \Hambelela\EPI\Performance::configure($pdo);
    $service = new \Hambelela\EPI\PerformanceScore($pdo);
    $kind = (string) ($_GET['kind'] ?? 'dashboard');
    $employees = $service->employeeOptions();
    $employeeIds = array_map('intval', array_column($employees, 'id'));
    $employeeId = (int) ($_GET['employee_id'] ?? ($employeeIds[0] ?? 0));
    if (!in_array($employeeId, $employeeIds, true)) $employeeId = (int) ($employeeIds[0] ?? 0);
    if ($employeeId <= 0) throw new RuntimeException('No eligible employee is available for performance reporting.');
    $year = max(2020, min(2100, (int) ($_GET['year'] ?? date('Y'))));
    $month = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));

    if ($kind === 'export') {
        if (!$owner) throw new RuntimeException('Owner access required.');
        $score = $service->getMonthlyScore($employeeId, $year, $month);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="employee-performance-' . $year . '-' . sprintf('%02d', $month) . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Employee', 'Period', 'Category', 'Weight', 'Category score', 'Contribution', 'Deductions', 'Positive evidence']);
        foreach (($score['categories'] ?? []) as $row) {
            fputcsv($out, [$score['employee_name'], $score['period_start'] . ' to ' . $score['period_end'], $row['category_name'], epiDashboardPercent($row['weight_hundredths']), epiDashboardPercent($row['final_hundredths']), epiDashboardPercent($row['weighted_contribution_hundredths']), epiDashboardPercent($row['deduction_hundredths']), epiDashboardPercent($row['positive_hundredths'])]);
        }
        fclose($out);
        exit;
    }

    $score = $service->getMonthlyScore($employeeId, $year, $month);
    if (!$owner && (!empty($score) && (int) ($score['locked'] ?? 0) !== 1)) $score = [];
    $previousDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('-1 month');
    $previous = $service->getMonthlyScore($employeeId, (int) $previousDate->format('Y'), (int) $previousDate->format('n'));
    if (!$owner && (!empty($previous) && (int) ($previous['locked'] ?? 0) !== 1)) $previous = [];
    $trend = $service->getTrend($employeeId, 12);
    if (!$owner) $trend = array_values(array_filter($trend, static function (array $row): bool { return (int) ($row['locked'] ?? 0) === 1; }));

    $workforce = [];
    if ($owner) {
        foreach ($employees as $employee) {
            $employeeScore = $service->getMonthlyScore((int) $employee['id'], $year, $month);
            $workforce[] = [
                'id' => (int) $employee['id'],
                'name' => (string) $employee['full_name'],
                'role_key' => (string) ($employee['role_key'] ?? ''),
                'score' => $employeeScore ? epiDashboardPercent($employeeScore['official_score_hundredths'] ?? null) : null,
                'level' => $employeeScore ? (string) ($employeeScore['official_performance_level'] ?? 'Not Available') : 'Not measured',
                'status' => $employeeScore ? (string) $employeeScore['score_status'] : 'insufficient_data',
                'pending' => $employeeScore ? (int) $employeeScore['pending_review_count'] : 0,
                'confidence' => $employeeScore ? (string) $employeeScore['confidence_label'] : 'Insufficient Data',
            ];
        }
    }

    $periodStart = sprintf('%04d-%02d-01', $year, $month);
    $periodEnd = (new DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
    $activityStatement = $pdo->prepare("SELECT module,COUNT(*) total FROM epi_employee_evidence WHERE employee_id=? AND business_date BETWEEN ? AND ? AND business_date>='2026-07-01' AND recording_mode<>'test' GROUP BY module ORDER BY total DESC,module");
    $activityStatement->execute([$employeeId, $periodStart, $periodEnd]);
    $workloadDistribution = $activityStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $heatmapStatement = $pdo->prepare("SELECT business_date,COUNT(*) meaningful_activity,SUM(eligibility_state='needs_review') needs_review,SUM(action LIKE '%overdue%' OR action LIKE '%late%' OR action LIKE '%missed%') overdue_events FROM epi_employee_evidence WHERE employee_id=? AND business_date BETWEEN ? AND ? AND business_date>='2026-07-01' AND recording_mode<>'test' GROUP BY business_date ORDER BY business_date");
    $heatmapStatement->execute([$employeeId, $periodStart, $periodEnd]);
    $heatmap = $heatmapStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $currentRisks = [];
    $riskQueries = [
        ['Orders outstanding', "SELECT COUNT(*) FROM ops_orders WHERE created_at>='2026-07-01' AND LOWER(status) NOT IN ('completed','complete','packed','verified','cancelled','canceled','refunded','failed') AND (assigned_packer_id=? OR ? IN (SELECT e.id FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND r.role_key LIKE 'front_desk%'))"],
        ['Packing List items outstanding', "SELECT COUNT(*) FROM ops_packing_tasks WHERE date_loaded>='2026-07-01' AND deleted_at IS NULL AND assigned_employee_id=? AND packing_status NOT IN ('done','website','packed_label_needed','done_needs_label','label_created')"],
        ['Tasks overdue', "SELECT COUNT(*) FROM ops_checklist_tasks WHERE created_at>='2026-07-01' AND deleted_at IS NULL AND assigned_employee_id=? AND status NOT IN ('completed','complete','approved') AND deadline<NOW()"],
        ['Unresolved quality records', "SELECT COUNT(*) FROM ops_error_logs WHERE logged_at>='2026-07-01' AND deleted_at IS NULL AND employee_id=? AND status<>'resolved'"],
    ];
    foreach ($riskQueries as [$label, $sql]) {
        try {
            $statement = $pdo->prepare($sql);
            $parameterCount = substr_count($sql, '?');
            $statement->execute(array_fill(0, $parameterCount, $employeeId));
            $count = (int) $statement->fetchColumn();
            $currentRisks[] = ['label' => $label, 'count' => $count, 'status' => $count > 0 ? 'attention' : 'clear'];
        } catch (Throwable $riskError) {
            $currentRisks[] = ['label' => $label, 'count' => null, 'status' => 'unavailable'];
        }
    }
    $insights = [];
    foreach ($currentRisks as $risk) if (($risk['count'] ?? 0) > 0) $insights[] = $risk['count'] . ' ' . strtolower($risk['label']) . ' currently require attention.';
    if ($score) {
        if ((int) ($score['pending_review_count'] ?? 0) > 0) $insights[] = (int) $score['pending_review_count'] . ' ambiguous score event(s) require owner review before month lock.';
        if (($score['official_score_hundredths'] ?? null) === null) $insights[] = 'The operational result is not calculated because verified source coverage is insufficient.';
    }

    echo json_encode([
        'ok' => true,
        'owner' => $owner,
        'feature_enabled' => \Hambelela\EPI\Performance::enabled(),
        'period' => ['year' => $year, 'month' => $month, 'label' => date('F Y', mktime(0, 0, 0, $month, 1, $year))],
        'employees' => $employees,
        'score' => $score ? epiDashboardPublicScore($score, $owner) : null,
        'previous_score' => $previous ? epiDashboardPercent($previous['official_score_hundredths'] ?? null) : null,
        'trend' => array_map(static function (array $row): array { return ['label' => sprintf('%04d-%02d', $row['score_year'], $row['score_month']), 'score' => epiDashboardPercent($row['official_score_hundredths'] ?? null), 'level' => $row['official_performance_level'] ?? 'Not Available', 'result_type' => $row['result_type'] ?? 'legacy', 'locked' => (int) $row['locked'] === 1]; }, $trend),
        'workforce' => $workforce,
        'workload_distribution' => array_map(static function (array $row): array { return ['label' => (string) $row['module'], 'value' => (int) $row['total']]; }, $workloadDistribution),
        'heatmap' => array_map(static function (array $row): array { return ['date' => (string) $row['business_date'], 'activity' => (int) $row['meaningful_activity'], 'needs_review' => (int) $row['needs_review'], 'overdue' => (int) $row['overdue_events']]; }, $heatmap),
        'current_risks' => $currentRisks,
        'management_insights' => $insights,
        'excluded_accounts' => [['classification' => 'Test / Preview Account', 'name_match' => 'Karina / Kaarina', 'included_in_calculations' => false]],
        'generated_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    error_log('EPI dashboard API: ' . $error->getMessage());
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
