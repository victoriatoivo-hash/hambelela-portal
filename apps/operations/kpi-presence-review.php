<?php
declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/kpi-reporting.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        kpi_send_json(['ok'=>false,'message'=>'Method not allowed.'], 405);
    }
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    $expected = (string) ($_SESSION['kpi_presence_csrf_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $submitted)) {
        kpi_send_json(['ok'=>false,'message'=>'Your session expired. Refresh and try again.'], 403);
    }
    $employeeId = max(0, (int) ($_POST['employee_id'] ?? 0));
    $date = trim((string) ($_POST['evidence_date'] ?? ''));
    $classification = trim((string) ($_POST['classification'] ?? ''));
    $note = trim((string) ($_POST['owner_note'] ?? ''));
    $allowed = [
        'confirmed_late_portal_start'=>'negative', 'confirmed_work_away'=>'positive',
        'approved_break'=>'excluded', 'approved_absence'=>'excluded', 'rest_day'=>'excluded',
        'public_holiday'=>'excluded', 'portal_outage'=>'excluded', 'schedule_change'=>'excluded',
        'unexplained'=>'negative', 'no_performance_impact'=>'positive',
    ];
    if (!$employeeId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !isset($allowed[$classification]) || $note === '') {
        kpi_send_json(['ok'=>false,'message'=>'Choose a classification and enter an owner note.'], 422);
    }
    $employee = ops_rows("SELECT id,full_name FROM ops_employees WHERE id=? AND status='active' LIMIT 1", [$employeeId])[0] ?? null;
    if (!$employee) kpi_send_json(['ok'=>false,'message'=>'Employee not found.'], 404);
    $actor = ops_current_employee_id();
    db()->prepare('INSERT INTO kpi_portal_presence_reviews (employee_id,evidence_date,classification,owner_note,score_effect,source_snapshot_json,reviewed_by,reviewed_at) VALUES (?,?,?,?,?,?,?,NOW())')
        ->execute([$employeeId,$date,$classification,$note,$allowed[$classification],(string)($_POST['source_snapshot_json']??'{}'),$actor]);
    $reviewId = (int) db()->lastInsertId();
    ops_activity_log('kpi_portal_presence_reviewed','kpi_portal_presence_review',$reviewId,[
        'employee_id'=>$employeeId,'employee_name'=>$employee['full_name'],'evidence_date'=>$date,
        'classification'=>$classification,'score_effect'=>$allowed[$classification],'owner_note'=>$note,
    ]);
    kpi_send_json(['ok'=>true,'message'=>'Portal presence evidence reviewed.','review_id'=>$reviewId]);
} catch (Throwable $error) {
    error_log(date(DATE_ATOM).' KPI presence review failed: '.$error->getMessage().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');
    kpi_send_json(['ok'=>false,'message'=>'The review could not be saved.'],500);
}
