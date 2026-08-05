<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('29d28bc57acc4104964322bec522f8f1', (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit('Not found');
}

try {
    require_once __DIR__ . '/shared/database.php';
    require_once __DIR__ . '/shared/epi/bootstrap.php';

    $service = new \Hambelela\EPI\PerformanceScore(db());
    $period = new DateTimeImmutable('first day of last month');
    $year = (int) $period->format('Y');
    $month = (int) $period->format('n');
    $superseded = $service->supersedeInvalidHundreds(null);

    echo 'period: ' . $period->format('Y-m') . PHP_EOL;
    echo 'superseded: ' . json_encode($superseded, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    foreach ($service->employeeOptions() as $employee) {
        $employeeId = (int) $employee['id'];
        try {
            $service->syncEvidenceEvents($employeeId, $year, $month);
            $result = $service->calculateMonthly($employeeId, $year, $month, null, 'step3b_historical_recalculation', 'Recovery Step 3B source-completeness recalculation.');
            echo implode(' | ', [
                (string) $employeeId,
                (string) $employee['full_name'],
                (string) ($result['result_type'] ?? 'unknown'),
                ($result['official_score_hundredths'] ?? null) === null ? 'NOT_CALCULATED' : number_format(((int) $result['official_score_hundredths']) / 100, 2),
                (string) ($result['confidence_label'] ?? 'unknown'),
                number_format(((int) ($result['data_completeness_hundredths'] ?? 0)) / 100, 2) . '%',
            ]) . PHP_EOL;
        } catch (Throwable $employeeError) {
            echo $employeeId . ' | ' . $employee['full_name'] . ' | ERROR | ' . $employeeError->getMessage() . PHP_EOL;
        }
    }
} catch (Throwable $error) {
    http_response_code(500);
    echo 'ERROR: ' . $error->getMessage();
}
