<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/epi/HrAbsenceEvidence.php';
use Hambelela\EPI\HrAbsenceEvidence;
$count = 0;
function check($actual, $expected): void {
    global $count;
    if ($actual !== $expected) throw new RuntimeException('Unexpected result: '.json_encode([$actual,$expected]));
    $count++;
}
$row = ['id'=>7,'employee_id'=>23,'status'=>'approved','start_date'=>'2026-10-06','end_date'=>'2026-10-07','approved_at'=>'2026-10-05 12:00:00','approved_by'=>81];
$r = HrAbsenceEvidence::classify([$row], '2026-10-07 23:59:59');
check($r['state'], 'approved_absence');
check($r['evidence'][0]['effective_to'], '2026-10-08 00:00:00');
check($r['evidence'][0]['hr_reviewer_user_id'], 81);
check(isset($r['evidence'][0]['portal_reviewer_id']), false);
check(HrAbsenceEvidence::classify([$row], '2026-10-08 00:00:00')['state'], 'no_approved_absence');
check(HrAbsenceEvidence::classify([$row], '2026-10-05 23:59:59')['state'], 'no_approved_absence');
check(HrAbsenceEvidence::classify([array_merge($row,['approved_at'=>'2026-10-08 12:00:00'])], '2026-10-06 10:00:00')['reason'], 'approval_after_deadline');
check(HrAbsenceEvidence::classify([array_merge($row,['status'=>'rejected'])], '2026-10-06 10:00:00')['state'], 'no_approved_absence');
check(HrAbsenceEvidence::classify([array_merge($row,['approved_by'=>0])], '2026-10-06 10:00:00')['state'], 'needs_review');
check(HrAbsenceEvidence::classify([array_merge($row,['start_date'=>'2026-02-31'])], '2026-10-06 10:00:00')['state'], 'needs_review');
check(HrAbsenceEvidence::classify([], 'not a date')['state'], 'needs_review');
echo "$count HR absence boundary checks passed\n";
