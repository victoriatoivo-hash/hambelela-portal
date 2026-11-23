<?php
declare(strict_types=1);
// Pure calculation tests: no application bootstrap, database or history writes.
$source = file_get_contents(__DIR__ . '/../apps/operations/kpi-reporting.php');
$start = strpos($source, 'function kpi_calculate_role_score(');
$end = strpos($source, 'function kpi_weighted_subscore(', $start);
if ($start === false || $end === false) throw new RuntimeException('Calculation not found');
eval(substr($source, $start, $end - $start));
function check_restore($actual, $expected, string $message): void {
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . json_encode([$actual, $expected]));
}
$full = kpi_calculate_role_score([
    ['weight'=>40,'result'=>90], ['weight'=>30,'result'=>80],
    ['weight'=>10,'result'=>100], ['weight'=>10,'result'=>100], ['weight'=>10,'result'=>100],
]);
check_restore($full['score'], 90.0, 'Original weighted role score');
check_restore($full['provisional'], false, 'Complete weight');
check_restore($full['band'], 'Gold', 'Original grade boundary');
$partial = kpi_calculate_role_score([['weight'=>40,'result'=>80], ['weight'=>60,'result'=>null]]);
check_restore($partial['score'], 80.0, 'Original measured-weight calculation');
check_restore($partial['measured_weight'], 40.0, 'Missing weight remains visible');
check_restore($partial['provisional'], true, 'Partial evidence labelled');
check_restore($partial['renormalised'], true, 'Original normalisation disclosed');
$missing = kpi_calculate_role_score([['weight'=>100,'result'=>null]]);
check_restore($missing['score'], null, 'Missing score is not zero');
$zero = kpi_calculate_role_score([['weight'=>100,'result'=>0]]);
check_restore($zero['score'], 0.0, 'Genuine measured zero remains zero');
echo "Legacy role score unit tests passed; no database accessed.\n";
