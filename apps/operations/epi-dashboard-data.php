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
            'evidence_uuid' => (string) ($event['evidence_uuid'] ?? ''),
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
            'score' => epiDashboardPercent($category['final_hundredths']),
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
        'score' => epiDashboardPercent($score['final_hundredths']),
        'performance_level' => (string) $score['performance_level'],
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
    $employeeId = (int) ($_GET['employee_id'] ?? $viewerId);
    if (!$owner) $employeeId = $viewerId;
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

    $employees = $owner ? $service->employeeOptions() : [['id' => $viewerId, 'full_name' => (string) ($user['name'] ?? 'Employee'), 'role_key' => (string) ($user['role_key'] ?? '')]];
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
                'score' => $employeeScore ? epiDashboardPercent($employeeScore['final_hundredths']) : null,
                'level' => $employeeScore ? (string) $employeeScore['performance_level'] : 'Not measured',
                'status' => $employeeScore ? (string) $employeeScore['score_status'] : 'insufficient_data',
                'pending' => $employeeScore ? (int) $employeeScore['pending_review_count'] : 0,
                'confidence' => $employeeScore ? (string) $employeeScore['confidence_label'] : 'Insufficient Data',
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'owner' => $owner,
        'feature_enabled' => \Hambelela\EPI\Performance::enabled(),
        'period' => ['year' => $year, 'month' => $month, 'label' => date('F Y', mktime(0, 0, 0, $month, 1, $year))],
        'employees' => $employees,
        'score' => $score ? epiDashboardPublicScore($score, $owner) : null,
        'previous_score' => $previous ? epiDashboardPercent($previous['final_hundredths']) : null,
        'trend' => array_map(static function (array $row): array { return ['label' => sprintf('%04d-%02d', $row['score_year'], $row['score_month']), 'score' => epiDashboardPercent($row['final_hundredths']), 'level' => $row['performance_level'], 'locked' => (int) $row['locked'] === 1]; }, $trend),
        'workforce' => $workforce,
        'generated_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    error_log('EPI dashboard API: ' . $error->getMessage());
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
