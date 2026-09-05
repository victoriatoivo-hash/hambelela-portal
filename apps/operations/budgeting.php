<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_role('owner_admin');
require_once __DIR__.'/budgeting-model.php';
$pageTitle='Budgeting | '.APP_NAME;
$activeApp='budgeting';
$_SESSION['budget_csrf'] ??= bin2hex(random_bytes(32));
$error=''; $budget=null; $lists=[];
try {
    db()->exec("CREATE TABLE IF NOT EXISTS ops_purchase_budgets (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(16) NOT NULL, title VARCHAR(160) NOT NULL, budget_date DATE NOT NULL, items_json MEDIUMTEXT NOT NULL, revision INT NOT NULL DEFAULT 1, created_by INT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX budget_period (budget_date,kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!hash_equals($_SESSION['budget_csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Your session changed. Reload before saving.');
        $input=budget_validate($_POST);
        $json=json_encode($input['items'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $id=(int)($_POST['id']??0);
        if ($id>0) {
            $stmt=db()->prepare('UPDATE ops_purchase_budgets SET kind=?,title=?,budget_date=?,items_json=?,revision=revision+1,updated_at=NOW() WHERE id=? AND revision=?');
            $stmt->execute([$input['kind'],$input['title'],$input['date'],$json,$id,(int)($_POST['revision']??0)]);
            if ($stmt->rowCount()!==1) throw new RuntimeException('This list changed in another window. Copy your entries before reloading.');
        } else {
            $stmt=db()->prepare('INSERT INTO ops_purchase_budgets (kind,title,budget_date,items_json,created_by) VALUES (?,?,?,?,?)');
            $stmt->execute([$input['kind'],$input['title'],$input['date'],$json,(int)current_user()['id']]);
            $id=(int)db()->lastInsertId();
        }
        header('Location: budgeting.php?id='.$id.'&saved=1'); exit;
    }
    $id=max(0,(int)($_GET['id']??0));
    if ($id) { $stmt=db()->prepare('SELECT * FROM ops_purchase_budgets WHERE id=?');$stmt->execute([$id]);$budget=$stmt->fetch(PDO::FETCH_ASSOC);if(!$budget)throw new RuntimeException('Budget not found.'); }
    $month=(string)($_GET['month']??substr((string)($budget['budget_date']??date('Y-m-d')),0,7));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month))throw new RuntimeException('Choose a valid month.');
    $stmt=db()->prepare('SELECT * FROM ops_purchase_budgets WHERE budget_date>=? AND budget_date<? ORDER BY budget_date DESC,id DESC');
    $stmt->execute([$month.'-01',date('Y-m-d',strtotime($month.'-01 +1 month'))]);$lists=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) { $error=$e instanceof RuntimeException?$e->getMessage():'Budgeting could not load. Please try again.';error_log('Budgeting: '.$e->getMessage()); }
function bh($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$editing=isset($_GET['new'])||$budget||$_SERVER['REQUEST_METHOD']==='POST';
$values=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:($budget?['id'=>$budget['id'],'revision'=>$budget['revision'],'kind'=>$budget['kind'],'title'=>$budget['title'],'date'=>$budget['budget_date'],'items'=>json_decode($budget['items_json'],true)]:['kind'=>($_GET['new']??'supplier')==='office'?'office':'supplier','date'=>date('Y-m-d'),'items'=>[]]);
$extraStylesheets=[['path'=>'assets/css/budgeting.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/budgeting.css')]];
include BASE_PATH.'/shared/header.php';
include BASE_PATH.'/shared/sidebar.php';
?>
<main class="workspace module budget-page" id="budgetingPage">
<header class="module-header"><h1>Budgeting</h1><p>Plan supplier orders and monthly office purchases.</p></header>
<?php if($error):?><p role="alert" class="ops-alert error"><?=bh($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])&&!$error):?><p role="status">Budget saved.</p><?php endif;?>
<nav class="budget-cards"><a href="?new=supplier"><span>Stock purchasing</span><h2>Supplier budget</h2><p>Create a dated list for Fourchem, Chempack, Nautica or any supplier.</p></a><a href="?new=office"><span>Office planning</span><h2>Office expenses</h2><p>Plan supplies and other purchases for the month.</p></a></nav>
<?php if($editing):?>
<section class="budget-sheet"><header><h2><?=($values['id']??0)?'Edit budget':'New budget'?></h2><a href="budgeting.php">All budgets</a></header>
<form method="post" id="budget-form">
<input type="hidden" name="csrf" value="<?=bh($_SESSION['budget_csrf'])?>"><input type="hidden" name="id" value="<?=bh($values['id']??0)?>"><input type="hidden" name="revision" value="<?=bh($values['revision']??0)?>">
<div class="budget-fields"><label>Type<select name="kind"><option value="supplier" <?=$values['kind']==='supplier'?'selected':''?>>Supplier budget</option><option value="office" <?=$values['kind']==='office'?'selected':''?>>Office expenses</option></select></label><label>Supplier / list name<input name="title" maxlength="160" required list="suppliers" value="<?=bh($values['title']??'')?>" placeholder="e.g. Fourchem"><datalist id="suppliers"><option>Fourchem</option><option>Chempack</option><option>Nautica</option></datalist></label><label>Budget date<input type="date" name="date" required value="<?=bh($values['date']??'')?>"></label></div>
<p class="budget-help">Enter the cost for the entire quantity on that row, just like your spreadsheet. It is not multiplied again. Include any delivery or other charges as separate rows.</p>
<div class="budget-table"><table><thead><tr><th>Quantity / size</th><th>Item</th><th>Cost for this row (N$)</th><th></th></tr></thead><tbody id="budget-rows"></tbody></table></div>
<button type="button" id="budget-add">+ Add item</button>
<div class="budget-total"><span>Priced subtotal</span><strong id="budget-total">N$0.00</strong><small id="budget-missing"></small></div>
<div class="budget-actions"><button type="submit" class="budget-primary">Save budget</button><button type="button" id="budget-copy">Copy order list</button><button type="button" id="budget-copy-costs">Copy with costs</button><span id="budget-copy-status" role="status"></span></div>
<textarea id="budget-copy-fallback" hidden readonly aria-label="Copy this purchase list"></textarea>
</form></section>
<script type="application/json" id="budget-initial"><?=json_encode($values['items']??[],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?></script>
<?php endif;?>
<section class="budget-sheet"><header><h2>Saved budgets</h2><form method="get"><label>Month <input type="month" name="month" value="<?=bh($month??date('Y-m'))?>" required></label><button>Show</button></form></header>
<div class="budget-list"><?php foreach($lists as $list):$items=json_decode($list['items_json'],true)?:[];$summary=budget_summary($items);?><a href="?id=<?=(int)$list['id']?>"><span><?=bh($list['kind']==='supplier'?'Supplier budget':'Office expenses')?> · <?=bh($list['budget_date'])?></span><h3><?=bh($list['title'])?></h3><strong>N$<?=number_format($summary['cents']/100,2)?></strong><small><?=count($items)?> items<?= $summary['missing']?' · '.$summary['missing'].' unpriced · subtotal only':''?></small></a><?php endforeach;?><?php if(!$lists):?><p>No saved budgets for this month. Create a supplier or office budget above.</p><?php endif;?></div></section>
</main><script src="<?=BASE_URL?>/assets/js/budgeting.js?v=1"></script>
<?php include BASE_PATH.'/shared/footer.php';?>
