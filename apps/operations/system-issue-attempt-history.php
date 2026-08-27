<?php
declare(strict_types=1);
if(!isset($selected)||!system_issue_is_owner())return;
$attempts=ops_rows('SELECT a.*,e.full_name recorded_by_name,t.full_name testing_by_name,d.full_name deployment_by_name FROM system_issue_repair_attempts a LEFT JOIN ops_employees e ON e.id=a.recorded_by_user_id LEFT JOIN ops_employees t ON t.id=a.testing_completed_by_user_id LEFT JOIN ops_employees d ON d.id=a.deployment_recorded_by_user_id WHERE a.issue_id=? ORDER BY a.attempt_number DESC,a.id DESC',[(int)$selected['id']]);
$fields=['approved_brief_version'=>'Approved brief version','repair_summary'=>'Repair summary','branch_name'=>'Branch','commit_hash'=>'Commit','files_changed'=>'Files changed','tests_performed'=>'Tests performed','tests_passed'=>'Tests reported passed','tests_unavailable'=>'Tests unavailable','status'=>'Testing status','testing_completed_at'=>'Testing completed at','testing_by_name'=>'Testing confirmed by','known_limitations'=>'Known limitations','failure_reason'=>'Failure reason','deployment_method'=>'Deployment method','deployment_time'=>'Deployment time','deployed_commit'=>'Deployed commit','deployment_result'=>'Deployment result','deployment_notes'=>'Deployment notes','deployment_by_name'=>'Deployment recorded by'];
?>
<section class="system-issue-attempt-history"><h3>Repair attempts</h3>
<?php if(!$attempts):?><p>No repair result has been recorded yet.</p><?php endif;?>
<?php foreach($attempts as$index=>$attempt):
    $rawUrl=trim((string)($attempt['pull_request_url']??''));$url=filter_var($rawUrl,FILTER_VALIDATE_URL);
    $validPr=$url&&strtolower((string)parse_url($url,PHP_URL_SCHEME))==='https'&&strtolower((string)parse_url($url,PHP_URL_HOST))==='github.com';
?>
<article class="system-issue-attempt-card<?=$index===0?' is-latest':''?>"><header><strong>Attempt <?=(int)$attempt['attempt_number']?></strong><?php if($index===0):?><span>Latest</span><?php endif;?><small><?=htmlspecialchars((string)($attempt['status']??'recorded'))?></small></header><dl>
<?php foreach($fields as$key=>$label):$value=trim((string)($attempt[$key]??''));?><div><dt><?=htmlspecialchars($label)?></dt><dd><?=nl2br(htmlspecialchars($value!==''?$value:'—'))?></dd></div><?php endforeach;?>
<div><dt>Deployment required</dt><dd><?=!empty($attempt['deployment_required'])?'Yes':'No'?></dd></div>
<div><dt>Recorded by</dt><dd><?=htmlspecialchars((string)($attempt['recorded_by_name']??'Owner'))?> · <?=htmlspecialchars((string)$attempt['recorded_at'])?></dd></div>
<div><dt>Pull request</dt><dd><?php if($validPr):?><a href="<?=htmlspecialchars((string)$url)?>" target="_blank" rel="noopener noreferrer"><?=htmlspecialchars((string)$url)?></a><?php else:?><?=htmlspecialchars($rawUrl!==''?$rawUrl:'—')?><?php endif;?></dd></div>
</dl></article>
<?php endforeach;?></section>
