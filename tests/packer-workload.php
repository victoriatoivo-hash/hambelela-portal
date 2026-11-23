<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/shared/epi/PackerOrdersPreview.php';
require_once dirname(__DIR__).'/shared/epi/PackerWorkload.php';
use Hambelela\EPI\PackerOrdersPreview as Engine;
use Hambelela\EPI\PackerWorkload as Workload;
$count=0;
function check($a,$b,$name){global$count;$count++;if($a!==$b)throw new RuntimeException($name.': '.json_encode([$a,$b]));}
$rows=[];
foreach([7=>190,8=>90]as$employee=>$quantity)for($i=0;$i<$quantity;$i++)$rows[]=['id'=>count($rows)+1,'packed_by'=>$employee,'responsible_id'=>null,'assigned_id'=>$employee,'packed_at'=>'2026-10-02 09:00:00','due_at'=>'2026-10-02 10:00:00','mode'=>'delivery','result'=>$i<10?'Late':'On Time','working_minutes'=>$i<10?180:60,'score_outcome'=>null];
$before=serialize($rows);$a=Engine::summary($rows,7,'2026-10-01','2026-10-31');$b=Engine::summary($rows,8,'2026-10-01','2026-10-31');
check(190,$a['packed'],'higher output preserved');check(90,$b['packed'],'lower output preserved');check(60.0,$a['median'],'median not skewed by outliers');check(null,$a['score'],'unknown ownership does not generate score');
check(67.9,round(100*$a['packed']/($a['packed']+$b['packed']),1),'share of team volume');
$w=Workload::contribution($rows,7,'2026-10-01','2026-10-31',[1=>['lines'=>3,'units'=>6],2=>['lines'=>2,'units'=>2]]);
check(2,$w['items_known_orders'],'missing items not zero coverage');check(8.0,$w['item_units'],'known units sum');check(0,$w['responsibilities'],'current assignment not proof');
$rows[0]['responsible_id']=7;$rows[190]['responsible_id']=7;
$rows[]=['id'=>999,'packed_by'=>null,'responsible_id'=>7,'packed_at'=>null,'due_at'=>'2026-09-30 10:00:00','result'=>'Overdue'];
$w=Workload::contribution($rows,7,'2026-10-01','2026-10-31',[]);
check(3,$w['responsibilities'],'deadline cohort plus open carryover');check(2,$w['fulfilled'],'completed within same cohort');check(1,$w['fulfilled_by_colleague'],'colleague assistance not hidden');check(1,$w['open'],'open work');check(1,$w['overdue'],'overdue work');
$clock=new Engine();
check(36000,Workload::intervalSeconds($clock,[['2026-10-02 00:00:00','2026-10-03 00:00:00'],['2026-10-02 12:00:00','2026-10-02 14:00:00'],['2026-10-03 09:00:00','2026-10-03 10:00:00']],'2026-10-02 00:00:00','2026-10-03 13:00:00'),'overlapping leave and cover deducted once');
check(0,Workload::intervalSeconds($clock,[['2026-11-01 08:00:00','2026-11-01 17:00:00']],'2026-11-01 00:00:00','2026-11-01 23:59:59'),'Sunday no deduction');
$even=[$rows[0],$rows[1]];$even[0]['working_minutes']=20;$even[1]['working_minutes']=40;
check(30.0,Engine::summary($even,7,'2026-10-01','2026-10-31')['median'],'even median');
echo "PASS: $count workload, denominator, median, ownership and capacity checks\n";
