<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_login(); require_role('owner_admin');
require_once __DIR__.'/../../shared/epi/DeadlineRateQuery.php';
header('Cache-Control: no-store');
$period=(string)($_GET['period']??date('Y-m')); $report=null; $error=''; $names=[];
try {
    if(!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D',$period,$parts)) throw new InvalidArgumentException('Choose a valid month.');
    $db=db();
    // Enforce read-only queries and a consistent view across evidence and ownership reads.
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('START TRANSACTION READ ONLY');
    try {
        $report=(new \Hambelela\EPI\DeadlineRateQuery($db))->month((int)$parts[1],(int)$parts[2]);
        $names=$db->query('SELECT id,full_name FROM ops_employees')->fetchAll(PDO::FETCH_KEY_PAIR);
    } finally { $db->exec('ROLLBACK'); }
} catch(Throwable $e) { $error=$e instanceof InvalidArgumentException?$e->getMessage():'Evidence review is unavailable. No scores were changed.'; }
function epi_rate_escape($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EPI workload evidence</title>
<style>@font-face{font-family:Jost;src:url('/assets/fonts/jost-variable.woff2')}body{background:#f7f7f2;color:#263521;font:400 13px/1.5 Jost,sans-serif;margin:24px}h1,h2{font-weight:500}h1{font-size:24px}h2{font-size:18px}.table-wrap{overflow:auto;background:white;border:1px solid #dce2d4;border-radius:12px;margin:16px 0}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:10px;border-bottom:1px solid #e4e8de;vertical-align:top}th{background:#eef2e5;font-size:11px;font-weight:500}a{color:#28639b}button,input{font:inherit;padding:8px}button{background:#53673c;color:white;border:0;border-radius:6px}.notice{padding:14px;border:1px solid #dce2d4;background:#fff;border-radius:8px}</style></head><body>
<a href="epi-v2-shadow.php">Back to EPI shadow review</a><h1>Workload and deadline evidence</h1>
<p class="notice">Diagnostic observations, not an official performance rating. Rates cover recorded deadlines only. Shadow evidence remains excluded from employee scores. An empty denominator is unavailable, never 100%.</p>
<form method="get"><label>Reporting month <input type="month" name="period" value="<?=epi_rate_escape($period)?>" required></label> <button>View evidence</button></form>
<?php if($error):?><p role="alert"><?=epi_rate_escape($error)?></p><?php endif;?>
<?php if($report):?><p>As of <?=epi_rate_escape($report['as_of'])?> · Due-month basis · Changes appear on reload; this page never recalculates or writes scores.</p>
<div class="table-wrap"><table><thead><tr><th>Responsible employee</th><th>Obligation</th><th>Observed workload</th><th>On time</th><th>Late</th><th>Observed on-time rate</th><th>Excluded / review</th><th>Pending</th></tr></thead><tbody>
<?php foreach($report['groups'] as $g):?><tr><td><?=epi_rate_escape($names[$g['employee_id']]??'Unattributed')?></td><td><?=epi_rate_escape($g['module'].' / '.$g['obligation_key'])?></td><td><?=$g['observed_volume']?></td><td><?=$g['on_time']?></td><td><?=$g['late']?></td><td><?=$g['observed_on_time_hundredths']===null?'Unavailable':number_format($g['observed_on_time_hundredths']/100,2).'%'?></td><td><?=$g['excluded']?></td><td><?=$g['pending']?></td></tr><?php endforeach;?>
<?php if(!$report['groups']):?><tr><td colspan="8">No recorded deadlines in this month. No score can be inferred.</td></tr><?php endif;?></tbody></table></div>
<h2>Evidence trail</h2><div class="table-wrap"><table><thead><tr><th>Record / obligation</th><th>Responsible employee</th><th>Started / due</th><th>Actual completion</th><th>Outcome / current state</th><th>Observation decision</th><th>Official scoring decision</th></tr></thead><tbody>
<?php foreach($report['evidence'] as $e):?><tr><td><?=epi_rate_escape($e['module'].' '.$e['object_reference'])?><br><?=epi_rate_escape($e['obligation_key'])?><details><summary>Identifiers and policy</summary><?=epi_rate_escape($e['deadline_uuid'])?><br><?=epi_rate_escape($e['incident_uuid']??'No breach incident')?><br><?=epi_rate_escape($e['policy_version'])?><br>Ownership: <?=epi_rate_escape($e['ownership']['ownership_uuid']??$e['ownership']['duty_uuid']??'Not established')?></details></td><td><?=epi_rate_escape($names[$e['employee_id']]??'Unattributed')?></td><td><?=epi_rate_escape($e['starts_at'])?><br><?=epi_rate_escape($e['due_at'])?></td><td><?=epi_rate_escape($e['fulfilled_at']??'Not completed')?><br><?=epi_rate_escape($names[$e['fulfilled_by']]??'')?></td><td><?=epi_rate_escape($e['outcome'].' / '.$e['current_state'])?></td><td><?=epi_rate_escape($e['observation_included']?'Included':($e['observation_exclusion']??'Pending'))?></td><td>Excluded: <?=epi_rate_escape($e['official_exclusion'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></body></html>
