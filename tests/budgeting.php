<?php
require __DIR__.'/../apps/operations/budgeting-model.php';
$v=budget_validate(['kind'=>'supplier','title'=>'Fourchem','date'=>'2026-09-05','items'=>[['quantity'=>'5kg','item'=>'Oil','cost'=>'425'],['quantity'=>'1L','item'=>'Oil 2','cost'=>'0.10'],['quantity'=>'2kg','item'=>'Unpriced','cost'=>'']]]);
if(budget_summary($v['items'])!==['cents'=>42510,'missing'=>1])throw new RuntimeException('Row totals must not multiply quantity.');
foreach(['-1','1.234','1e3','NaN'] as $cost){try{budget_cents($cost);throw new LogicException('Invalid cost accepted');}catch(RuntimeException $expected){}}
try{budget_validate(['kind'=>'office','title'=>'Office','date'=>'2026-02-30']);throw new LogicException('Invalid date accepted');}catch(RuntimeException $expected){}
echo "Budget validation and totals passed.\n";
