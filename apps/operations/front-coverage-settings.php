<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_login();
require_role('owner_admin');
require_once BASE_PATH.'/shared/epi/OwnerFrontCoverage.php';
use Hambelela\EPI\{OwnerFrontCoverage,HrConnection,Support};
header('Cache-Control: no-store');
$pageTitle='Front Desk Coverage | '.APP_NAME;$activeApp='settings';
$error='';$notice='';$rows=[];$people=[];$ready=false;
$today=Support::timestamp(null)->format('Y-m-d');
$day=(string)($_POST['day']??$_GET['day']??$today);
$values=['primary'=>'','start'=>'08:00','end'=>'17:00','cover'=>'','lunch_start'=>'13:00','lunch_end'=>'14:00','reason'=>''];
if(empty($_SESSION['owner_front_coverage_csrf']))$_SESSION['owner_front_coverage_csrf']=bin2hex(random_bytes(32));
try {
    OwnerFrontCoverage::date($day);
    $people=OwnerFrontCoverage::employees(db());$rows=OwnerFrontCoverage::rows(db(),$day);$ready=true;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $values=array_replace($values,array_intersect_key($_POST,$values));
        if(!hash_equals($_SESSION['owner_front_coverage_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Reload before saving.');
        $result=OwnerFrontCoverage::save(db(),HrConnection::connect(),(int)ops_current_employee_id(),$_POST);
        header('Location: front-coverage-settings.php?day='.rawurlencode($day).'&saved=1');exit;
    }
    $primary=array_values(array_filter($rows,function($r){return $r['responsibility_level']==='primary';}));
    $cover=array_values(array_filter($rows,function($r){return $r['responsibility_level']==='coverage';}));
    if($primary)$values['primary']=$primary[count($primary)-1]['employee_id'];
    if($rows){$values['start']=substr(max($day.' 00:00:00',$rows[0]['effective_from']),11,5);$last=$rows[count($rows)-1];$values['end']=substr(min($day.' 23:59:00',$last['effective_to']??$day.' 17:00:00'),11,5);}
    if(count($cover)===1){$values['cover']=$cover[0]['employee_id'];$values['lunch_start']=substr($cover[0]['effective_from'],11,5);$values['lunch_end']=substr($cover[0]['effective_to'],11,5);}
    if(isset($_GET['saved']))$notice='Coverage saved. Responsibility follows the times below automatically. Earlier work and employee actions are unchanged.';
}catch(Throwable $e){$error=$e instanceof PDOException?'Coverage could not be loaded or saved. No changes were applied. Please try again.':$e->getMessage();}
function fc_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function fc_options(array $people,$selected):void{foreach($people as $p)echo '<option value="'.(int)$p['id'].'"'.((string)$selected===(string)$p['id']?' selected':'').'>'.fc_h($p['full_name'].' · '.$p['role_name']).'</option>';}
include BASE_PATH.'/shared/header.php';
include BASE_PATH.'/shared/sidebar.php';
?>
<style>
.fc-settings{--fc-olive:#59694a;--fc-dark:#344126;--fc-border:#e4e6dc;font-family:Jost,sans-serif;color:var(--fc-dark);max-width:1120px;padding:24px;box-sizing:border-box}.fc-settings *{box-sizing:border-box}.fc-settings h1{font-size:24px;font-weight:600;margin:12px 0 4px}.fc-settings h2{font-size:15px;font-weight:600;margin:0 0 12px}.fc-settings p{margin:6px 0 14px;font-size:12px;line-height:1.6}.fc-settings a{color:var(--fc-olive);font-size:12px}.fc-settings .fc-grid{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:16px;align-items:start;margin-top:16px}.fc-settings .fc-card{background:white;border:1px solid var(--fc-border);border-radius:12px;padding:20px}.fc-settings .fc-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px}.fc-settings label{display:flex;flex-direction:column;gap:6px;font-size:11px;font-weight:500}.fc-settings .fc-wide{grid-column:1/-1}.fc-settings input,.fc-settings select,.fc-settings button{font:400 12px Jost,sans-serif;min-height:40px;border:1px solid var(--fc-border);border-radius:9px;background:white;color:var(--fc-dark);padding:8px 11px;max-width:100%;width:100%}.fc-settings button{font-weight:500;cursor:pointer;width:auto;transition:background .15s,box-shadow .15s}.fc-settings button:hover{background:#f0f3eb}.fc-settings .fc-primary{background:var(--fc-olive);color:white}.fc-settings .fc-primary:hover{background:var(--fc-dark)}.fc-settings :focus-visible{outline:2px solid var(--fc-olive);outline-offset:2px}.fc-settings .fc-date{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:18px}.fc-settings .fc-date label{width:190px}.fc-settings .fc-divider{border:0;border-top:1px solid var(--fc-border);margin:20px 0}.fc-settings .fc-info{background:#f2f4ed;border-radius:9px;padding:12px;font-size:12px;line-height:1.6;margin:14px 0}.fc-settings .fc-error{color:#9a3030;background:#fff4f4}.fc-settings .fc-slot{display:grid;grid-template-columns:110px minmax(0,1fr);gap:12px;padding:13px 0;border-bottom:1px solid var(--fc-border);font-size:12px}.fc-settings .fc-slot strong{font-weight:500}.fc-settings .fc-slot small{display:block;color:#69735e;margin-top:4px}.fc-settings .fc-cover{border-left:3px solid var(--fc-olive);padding-left:10px}.fc-settings .fc-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}.fc-settings [hidden]{display:none!important}.fc-settings fieldset{border:0;padding:0;margin:0;min-width:0}.fc-settings button:disabled{opacity:.5;cursor:not-allowed}@media(max-width:740px){.fc-settings{padding:16px}.fc-settings .fc-grid{grid-template-columns:1fr}.fc-settings input,.fc-settings select{font-size:16px}.fc-settings .fc-card{padding:16px}.fc-settings h1{font-size:22px}.fc-settings .fc-fields{gap:12px}.fc-settings .fc-slot{grid-template-columns:100px minmax(0,1fr)}}@media(prefers-reduced-motion:reduce){.fc-settings button{transition:none}}
</style>
<main class="workspace module fc-settings">
    <a href="my-account.php">← Settings</a>
    <h1>Front Desk Coverage</h1>
    <p>Owner-only scheduling · Africa/Windhoek</p>
    <?php if($error):?><div class="fc-info fc-error" role="alert"><?=fc_h($error)?></div><?php endif;?>
    <?php if($notice):?><div class="fc-info" role="status"><?=fc_h($notice)?></div><?php endif;?>
    <form method="get" class="fc-date"><label>Coverage date<input type="date" name="day" value="<?=fc_h($day)?>" required></label><button>View day</button></form>
    <div class="fc-grid">
    <section class="fc-card">
        <h2>Who is covering?</h2>
        <form method="post" id="fc-schedule">
            <input type="hidden" name="csrf" value="<?=fc_h($_SESSION['owner_front_coverage_csrf'])?>">
            <input type="hidden" name="day" value="<?=fc_h($day)?>">
            <input type="hidden" name="revision" value="<?=fc_h($_SERVER['REQUEST_METHOD']==='POST'?($_POST['revision']??''):OwnerFrontCoverage::revision($rows))?>">
            <fieldset <?=!$ready||$day<$today?'disabled':''?>>
            <div class="fc-fields">
                <label class="fc-wide">Front Desk person<select name="primary" required><option value="">Choose employee</option><?php fc_options($people,$values['primary']);?></select></label>
                <label>From<input type="time" name="start" value="<?=fc_h($values['start'])?>" required></label>
                <label>Until<input type="time" name="end" value="<?=fc_h($values['end'])?>" required></label>
            </div>
            <hr class="fc-divider">
            <h2>Lunch cover</h2>
            <div class="fc-fields">
                <label class="fc-wide">Person covering during lunch<select name="cover" id="fc-cover"><option value="">No lunch cover</option><?php fc_options($people,$values['cover']);?></select></label>
                <label class="fc-lunch">Lunch cover starts<input type="time" name="lunch_start" value="<?=fc_h($values['lunch_start'])?>"></label>
                <label class="fc-lunch">Front Desk person returns<input type="time" name="lunch_end" value="<?=fc_h($values['lunch_end'])?>"></label>
                <label class="fc-wide">Note (optional)<input name="reason" maxlength="500" value="<?=fc_h($values['reason'])?>" placeholder="For example, covering the counter during lunch"></label>
            </div>
            <div class="fc-info">Lunch cover takes responsibility only during the lunch window; it returns to the Front Desk person at the end. Today's changes apply from now, never to past work. Existing hours outside your chosen window remain unchanged.</div>
            <div class="fc-actions"><button type="submit" class="fc-primary">Save coverage</button></div>
            </fieldset>
        </form>
    </section>
    <aside class="fc-card" aria-label="Saved coverage">
        <h2>Saved schedule · <?=fc_h($day)?></h2>
        <?php if(!$rows):?><p>No coverage has been recorded for this day yet. The normal roster, if configured, is applied when the day starts.</p><?php endif;?>
        <?php foreach($rows as $row):?><div class="fc-slot <?=$row['responsibility_level']==='coverage'?'fc-cover':''?>"><span><?=fc_h(substr(max($row['effective_from'],$day.' 00:00:00'),11,5))?> – <?=fc_h(substr(min($row['effective_to']??$day.' 23:59:00',$day.' 23:59:00'),11,5))?></span><div><strong><?=fc_h($row['full_name'])?></strong><small><?=$row['responsibility_level']==='coverage'?'Lunch cover':'Front Desk'?></small></div></div><?php endforeach;?>
        <hr class="fc-divider"><h2>What follows this schedule?</h2>
        <p>Front Desk duties for walk-in orders, order follow-up, courier document handover and scheduled bookkeeping controls.</p>
        <p>Specific employee assignments stay with that employee. Packing assignments, account permissions and actual action history are not changed. Approved leave and existing evidence checks still apply.</p>
        <p>Choose from active Front Desk and Marketing & Sales profiles. To adjust lunch cover, change the person or times and save again; choose “No lunch cover” to remove a future lunch switch within the selected hours.</p>
        <?php if($day<$today):?><div class="fc-info">Past schedules are read-only.</div><?php endif;?>
    </aside>
    </div>
</main>
<script>
(()=>{const select=document.getElementById('fc-cover');const update=()=>{document.querySelectorAll('.fc-lunch').forEach(label=>{label.hidden=!select.value;label.querySelector('input').required=!!select.value;});};select.addEventListener('change',update);update();})();
</script>
<?php include BASE_PATH.'/shared/footer.php';?>
