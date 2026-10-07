<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_login();require_role('owner_admin','front_desk_admin');
use Hambelela\EPI\{V2Store,V2PerformanceQuery,OwnershipPeriodEngine,BusinessTimeEngine,Support};
header('Cache-Control: no-store');
$db=db();$actor=(int)ops_current_employee_id();$error='';$notice='';$activation=null;$health=[];
if(empty($_SESSION['epi_shadow_csrf']))$_SESSION['epi_shadow_csrf']=bin2hex(random_bytes(32));
try{
    $activation=V2Store::activation($db);$policy=V2Store::policy($db);$primary=(int)($policy['front_desk_employee_id']??0);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($_SESSION['epi_shadow_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Please reload and try again.');
        if(!$activation||$actor!==$primary)throw new RuntimeException('Only the configured Front Desk employee can confirm their own duty.');
        $now=Support::timestamp();$engine=new OwnershipPeriodEngine($db);$current=$engine->dutyAt('front_desk',$now);$action=(string)($_POST['action']??'');
        if($action==='start'){
            if(\Hambelela\EPI\FrontDeskRoster::enabled($db)){
                $availability=\Hambelela\EPI\HrAbsenceEvidence::inspect($db,ops_hr_db(),$actor,$now->format('Y-m-d H:i:s'));
                if($availability['state']!=='no_approved_absence')throw new RuntimeException('HR availability needs review before confirming duty.');
            }
            if(V2Store::absence($db,$actor,$now->format('Y-m-d H:i:s')))throw new RuntimeException('An approved absence is active. Contact the owner.');
            $window=(new BusinessTimeEngine($db))->windowForDate($now);
            if(!$window||$now<$window[0]||$now>=$window[1])throw new RuntimeException('Duty must begin within the saved business hours.');
            if(!$current)$engine->assignDuty(['duty_key'=>'front_desk','employee_id'=>$actor,'assigned_by'=>$actor,'accepted_by'=>$actor,'accepted_at'=>$now->format('Y-m-d H:i:s'),'effective_from'=>$now,'effective_to'=>$window[1],'source'=>'employee_self_confirmation','reason'=>'Employee confirms current Front Desk duty']);
            elseif((int)$current['employee_id']!==$actor)throw new RuntimeException('Another accepted duty exists; owner review required.');
            $notice='Duty confirmed until today’s closing time.';
        }elseif($action==='stop'){
            if(!$current||(int)$current['employee_id']!==$actor)throw new RuntimeException('No current duty to end.');
            V2Store::transaction($db,function()use($db,$current,$actor,$now){
                V2Store::lock($db,'duty|front_desk');$at=$now->format('Y-m-d H:i:s');
                if($at<=$current['effective_from'])throw new RuntimeException('Please wait a moment before ending duty.');
                $db->prepare('UPDATE epi_v2_duty_periods SET effective_to=? WHERE id=? AND employee_id=? AND effective_to>?')->execute([$at,$current['id'],$actor,$at]);
                V2Store::audit($db,'duty|front_desk',$actor,'Employee ended duty; no assumed coverage',$current,['effective_to'=>$at]);
            });$notice='Duty ended. No replacement employee has been assumed.';
        }else throw new RuntimeException('Unknown action.');
    }
    $health=(new V2PerformanceQuery($db))->watchdogHealth();
}catch(Throwable $e){$error=$activation?$e->getMessage():'Shadow setup is not active yet. Contact the owner.';}
function epi_shadow_escape($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EPI shadow trial</title>
<style>@font-face{font-family:Jost;src:url('/assets/fonts/jost-variable.woff2')}body{margin:0;background:#f7f7f2;color:#263521;font:400 14px/1.5 Jost,sans-serif}main{max-width:900px;margin:35px auto;padding:20px}section{background:white;border:1px solid #dfe4d6;border-radius:12px;padding:20px;margin:16px 0}h1{font-size:25px;font-weight:500}h2{font-size:16px;font-weight:500}button,a{font:inherit}button{border:0;background:#53673c;color:white;border-radius:7px;padding:9px 16px;margin:5px;cursor:pointer}button:hover{background:#354526}.warning{color:#8b4025}dl{display:grid;grid-template-columns:1fr 1fr;gap:8px}dd{margin:0}a{color:#28639b}</style>
<main><a href="/apps/operations/employee-performance.php">Back to Employee Performance</a><h1>Employee Performance — shadow trial</h1><p><a href="front-roster.php">Owner: approve Front Desk roster</a></p>
<section><h2>Observation only · official scores unchanged</h2><p>Only new owner-assigned tasks and quality records are captured. Every incident is excluded from official scoring. Missing order deadlines, unassigned work and historical backlog are not inferred.</p>
<?php if($error):?><p class="warning"><?=epi_shadow_escape($error)?></p><?php endif;?>
<?php if($notice):?><p><?=epi_shadow_escape($notice)?></p><?php endif;?>
<dl><dt>Activation</dt><dd><?=epi_shadow_escape($activation['enforcement_start_at']??'Not active')?></dd><dt>Watchdog</dt><dd><?=epi_shadow_escape($health['status']??'Unavailable')?></dd><dt>Last successful check</dt><dd><?=epi_shadow_escape($health['last_success']??'Not yet recorded')?></dd></dl>
<p>Checks are scheduled every five minutes. The scheduler may be delayed; breach timestamps remain the original deadlines. A gap over twenty minutes is shown as unhealthy.</p></section>
<section><h2>Front Desk duty</h2><p>The one configured Front Desk employee confirms when they are actually on duty. Ending duty does not assign coverage to anyone else. Do not confirm duty while absent or on leave.</p>
<?php if($activation&&$actor===($primary??0)):?><form method="post"><input type="hidden" name="csrf" value="<?=epi_shadow_escape($_SESSION['epi_shadow_csrf'])?>"><button name="action" value="start">Confirm I am on duty</button><button name="action" value="stop">End my duty</button></form><?php else:?><p>Duty confirmation is available on the Front Desk employee’s own account.</p><?php endif;?></section>
<section><h2>Trial limitations</h2><p>Orders stage tracking requires its separate approved migration and activation. Coverage and HR absence integration require further validation; this trial cannot be used for deductions or an official employee rating.</p><a href="epi-orders-deadlines.php">Owner: review Orders stage deadlines</a></section></main></html>
