<?php
declare(strict_types=1);
require __DIR__.'/../apps/operations/performance-activity.php';
$payments = true;
function ops_column_exists(string $table, string $column): bool { return $GLOBALS['payments']; }
function check($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$queries = [];
$reader = static function ($sql, $params) use (&$queries): array {
    $queries[] = [$sql, $params];
    check(strpos($sql, 'SELECT ') === 0, 'Only SELECT is allowed');
    if (strpos($sql, 'GROUP BY') === false) return [['total'=>3,'value'=>'432.10']];
    check(strpos($sql, "'trash'") !== false && strpos($sql, "'refunded'") !== false, 'Ineligible orders excluded');
    $isPayment = strpos($sql, 'DATE_ADD') !== false;
    if ($isPayment) {
        check(strpos($sql, 'portal_paid_confirmed=1') !== false, 'Only confirmed paid orders');
        check(strpos($sql, 'payment_updated_at') === false, 'Do not substitute edit dates');
    }
    return [['day'=>count($queries)<=2?'2026-10-27':'2026-10-20','total'=>$isPayment?'105.50':'2']];
};
$result = pw_business_activity(new DateTimeImmutable('2026-10-27T14:30:00+02:00'), $reader);
check(count($queries) === 5, 'Bounded aggregate reads');
check($queries[0][1] === ['2026-10-21 00:00:00','2026-10-27 14:30:00'], 'Current local range');
check($queries[1][1] === ['2026-10-20 22:00:00','2026-10-27 12:30:00'], 'UTC confirmation range');
check($queries[2][1] === ['2026-10-14 00:00:00','2026-10-20 14:30:00'], 'Previous matched elapsed range');
check(count($result['current']['days']) === 7 && $result['current']['days'][0]['orders'] === 0, 'Zero-fill absent days');
check($result['current']['orders'] === 2 && $result['current']['paid_value'] === 105.5, 'Correct aggregates');
check($result['awaiting'] === ['count'=>3,'value'=>432.1], 'All-open order value separate from receipts');
$queries=[];$payments=false;
$result=pw_business_activity(new DateTimeImmutable('2026-11-01T00:01:00+02:00'), $reader);
check(count($queries)===3 && $result['current']['paid_value']===null, 'Unavailable is not zero');
check($result['from']==='2026-10-26' && $queries[1][1][0]==='2026-10-19 00:00:00', 'Month crossing');
$payments=true;
$result=pw_business_activity(new DateTimeImmutable('2028-03-01T12:00:00+02:00'), static function(){return [];});
check(in_array('2028-02-29',array_column($result['current']['days'],'date'),true), 'Leap day retained');
check($result['current']['paid_value']===0.0 && $result['current']['orders']===0, 'Empty source totals are zero');
try { pw_business_activity(new DateTimeImmutable(), static function(){throw new RuntimeException('database unavailable');}); throw new LogicException('Failure became zero'); }
catch (RuntimeException $e) { check($e->getMessage()==='database unavailable','Read failures propagate to unavailable state'); }
echo "PASS: read-only queries, Namibia/UTC dates, equal elapsed windows, zero filling, totals, missing evidence, database failure, month/leap boundaries\n";
