<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_login();require_role('owner_admin');
header('Cache-Control: no-store');
$error='';$notice='';$rows=[];
if(empty($_SESSION['front_roster_csrf']))$_SESSION['front_roster_csrf']=bin2hex(random_bytes(32));
try {
    $primary=(int)(\Hambelela\EPI\V2Store::policy(db())['front_desk_employee_id']??0);
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!hash_equals($_SESSION['front_roster_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Reload and try again.');
        \Hambelela\EPI\FrontDeskRoster::approvePermanent(db(),(int)ops_current_employee_id());
        $notice='Roster approved. Scheduled duty remains subject to HR availability checks and feature activation.';
    }
    $q=db()->query('SELECT r.*,e.full_name FROM epi_v2_front_rosters r JOIN ops_employees e ON e.id=r.employee_id ORDER BY r.effective_from DESC LIMIT 100');$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
} catch(Throwable $e) {$error=$e instanceof PDOException?'Roster setup is not available yet. No automatic migration was run.':$e->getMessage();}
function fr_escape($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Front Desk roster approval</title><style>
@font-face{font-family:Jost;src:url('/assets/fonts/jost-variable.woff2')}body{font:400 13px/1.5 Jost,sans-serif;background:#f7f7f2;color:#263521;margin:24px}main{max-width:850px;margin:auto}h1{font-weight:500;font-size:24px}form,section{background:white;border:1px solid #dce2d4;border-radius:12px;padding:20px;margin:16px 0}label{display:block;margin:10px 0}input,button{font:inherit;padding:8px;border:1px solid #dce2d4;border-radius:7px}button{background:#53673c;color:white}table{border-collapse:collapse;width:100%}td,th{padding:8px;border-bottom:1px solid #dce2d4;text-align:left}.error{color:#9b3425}</style></head><body><main>
<a href="epi-v2-shadow.php">Back to EPI shadow review</a><h1>Permanent Front Desk responsibility</h1><p>Weekdays 08:00–17:00 · Saturday 09:00–13:00 · Sunday closed. The configured Front Desk employee is the permanent default, starting at the next scheduled opening. No weekly roster or employee login is required. Approved absence and accepted lunch coverage remain exceptions; this does not record attendance.</p>
<?php if($error):?><p class="error"><?=fr_escape($error)?></p><?php endif;?><?php if($notice):?><p><?=fr_escape($notice)?></p><?php endif;?>
<?php if(!$rows):?><form method="post"><input type="hidden" name="csrf" value="<?=fr_escape($_SESSION['front_roster_csrf'])?>"><button>Confirm permanent Front Desk responsibility</button></form><?php else:?><p>Responsibility is already recorded. A later permanent job change requires an audited transfer, not replacement of past records.</p><?php endif;?>
<section><table><thead><tr><th>Employee</th><th>From</th><th>Through</th><th>Approved at</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=fr_escape($r['full_name'])?></td><td><?=fr_escape($r['effective_from'])?></td><td><?=fr_escape($r['effective_to'])?></td><td><?=fr_escape($r['approved_at'])?></td></tr><?php endforeach;?></tbody></table></section></main></body></html>
