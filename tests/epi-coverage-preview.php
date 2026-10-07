<?php
// Synthetic browser fixture. Serve with PHP's loopback development server only.
if(PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){http_response_code(404);exit;}
$scenario=(string)($_GET['scenario']??'primary');
if(isset($_GET['api'])) {
    header('Content-Type: application/json');
    $cover=$scenario==='cover';
    $state=['enabled'=>true,'actor'=>$cover?4:2,'primary'=>2,'role'=>$cover?'marketing_sales':'front_desk_admin',
        'plan'=>$cover?['id'=>1,'coverage_employee_id'=>4,'state'=>'requested','planned_start'=>'2026-10-19 12:00:00','planned_end'=>'2026-10-19 13:00:00']:null,
        'prompt'=>!$cover,'alert'=>null,'hr_state'=>'no_approved_absence','candidates'=>[['id'=>4,'full_name'=>'Coverage employee (test)']]];
    if($_SERVER['REQUEST_METHOD']==='POST') {
        $action=$_POST['action']??'';
        $state['prompt']=false;
        $state['plan']=['id'=>1,'coverage_employee_id'=>4,'state'=>$action==='accept'?'accepted':'requested','planned_start'=>'2026-10-19 12:00:00','planned_end'=>'2026-10-19 13:00:00'];
    }
    echo json_encode(['ok'=>true,'csrf'=>'synthetic-only','data'=>$state]);exit;
}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Coverage preview — synthetic only</title>
<style>body{font:14px sans-serif;background:#f7f7f2;padding:32px;color:#263521}h1{font-size:24px}</style>
<link rel="stylesheet" href="/assets/css/front-coverage.css">
<h1>Front Desk coverage — test preview</h1><p>Synthetic identities only. This preview does not connect to a database or send notifications.</p>
<a href="?scenario=primary">Front Desk preview</a> · <a href="?scenario=cover">Coverage employee preview</a>
<script defer src="/assets/js/front-coverage.js" data-endpoint="?api=1&amp;scenario=<?=htmlspecialchars($scenario,ENT_QUOTES,'UTF-8')?>"></script></html>
