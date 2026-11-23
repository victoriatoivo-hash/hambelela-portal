<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/epi/PerformanceEvidenceView.php';
use Hambelela\EPI\PerformanceEvidenceView;
$rows=[];for($i=0;$i<130;$i++)$rows[]=['opportunity_key'=>'work:'.intdiv($i,2),'source_reference'=>'TASK-'.$i,'module'=>'Tasks','category'=>$i%2?'quality':'tasks','outcome'=>$i%3?'success':'failure','observed_at'=>'2026-10-'.str_pad((string)(1+$i%28),2,'0',STR_PAD_LEFT).' 08:00:00','expected'=>'Complete assigned task','actual'=>'Recorded outcome'];
$r=['result_id'=>42,'evidence'=>$rows];$hash=hash('sha256',serialize($r));
$check=static function($condition,$name){if(!$condition)throw new RuntimeException($name);};
$p=PerformanceEvidenceView::page($r);$check(count($p['records'])===25&&$p['total']===130&&$p['pages']===6,'bounded default');
$p=PerformanceEvidenceView::page($r,['page'=>2,'page_size'=>999]);$check(count($p['records'])===50&&$p['page']===2,'maximum page size');
$p=PerformanceEvidenceView::page($r,['category'=>'tasks','status'=>'failure']);foreach($p['records'] as $row)$check($row['category']==='tasks'&&$row['outcome']==='failure','filters');
$p=PerformanceEvidenceView::page($r,['search'=>'TASK-129']);$check($p['total']===1,'search');
$p=PerformanceEvidenceView::page($r,['date'=>'2026-10-02']);foreach($p['records'] as $row)$check(substr($row['observed_at'],0,10)==='2026-10-02','date');
$p=PerformanceEvidenceView::page($r,['page_size'=>5,'unique_work'=>true]);$check(count($p['records'])===5&&count(array_unique(array_column($p['records'],'opportunity_key')))===5,'five distinct preview work records');
$check(hash('sha256',serialize($r))===$hash,'immutable calculation untouched');
echo "PASS: published evidence bounded pagination, filters, unique preview and immutable source\n";
