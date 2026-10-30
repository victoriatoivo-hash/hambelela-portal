<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/shared/epi/PackerOrdersPreview.php';
use Hambelela\EPI\PackerOrdersPreview as Engine;
$n=0;
function eq($expected,$actual,string $name):void {global$n;$n++;if($expected!==$actual)throw new RuntimeException($name.': '.json_encode($actual).' != '.json_encode($expected));}
$e=new Engine();
foreach([
 ['2026-10-30 16:00:00','2026-10-31 10:00:00'],
 ['2026-10-31 12:30:00','2026-11-02 09:30:00'],
 ['2026-11-01 10:00:00','2026-11-02 10:00:00'],
 ['2026-11-02 17:00:00','2026-11-03 10:00:00'],
 ['2026-04-02 16:00:00','2026-04-04 10:00:00'],
 ['2026-04-04 12:00:00','2026-04-07 09:00:00'],
 ['2026-05-13 16:00:00','2026-05-15 09:00:00'],
 ['2026-05-27 16:00:00','2026-05-29 09:00:00'],
 ['2026-12-24 16:00:00','2026-12-28 09:00:00'],
 ['2027-03-20 12:00:00','2027-03-23 09:00:00'],
]as[$a,$b]){eq($b,$e->due($a),'calendar '.$a);eq(7200,$e->seconds($a,$b),'exact 120 working minutes');}
eq('2026-11-04 10:00:00',(new Engine(['2026-11-03']))->due('2026-11-03 09:00:00'),'saved closure');
eq('2026-10-30 10:00:00',Engine::time('2026-10-30T08:00:00Z')->format('Y-m-d H:i:s'),'UTC conversion');
try{Engine::time('2026-02-30 08:00:00');throw new LogicException('invalid date accepted');}catch(RuntimeException $x){$n++;}
$base=['id'=>1,'mode'=>'collection','status'=>'in_progress','paid'=>0,'contact'=>'0811234567','assigned_id'=>7];
$owner=[['employee_id'=>7,'effective_from'=>'2026-10-30 08:00:00','effective_to'=>null]];
$new=['kind'=>'new','at'=>'2026-10-30 08:00:00'];
$pack=['kind'=>'packed','at'=>'2026-10-30 10:00:00','actor'=>7];
$calc=fn($o=[],$events=null,$owners=null,$ex=[])=>$e->evaluate(array_replace($base,$o),$events??[$new,$pack],$owners??$owner,$ex,'2026-10-30 12:00:00');
$on=$calc();eq('On Time',$on['result'],'exact deadline on time');eq(120.0,$on['working_minutes'],'unpaid collection counts');
eq('On Time',$calc(['mode'=>'delivery'])['result'],'unpaid delivery counts');
$late=$calc([],[$new,array_replace($pack,['at'=>'2026-10-30 10:01:00'])]);eq('Late',$late['result'],'one minute late');eq(1.0,$late['overdue_minutes'],'late duration');
$over=$calc(['status'=>'new'],[$new]);eq('Overdue',$over['result'],'unpacked overdue');
$pending=$calc(['status'=>'new'],[array_replace($new,['at'=>'2026-10-30 11:00:00'])]);eq('Pending',$pending['result'],'not yet due');eq(null,$pending['score_outcome'],'pending not failed');
$closed=$e->evaluate(array_replace($base,['status'=>'new']),[['kind'=>'new','at'=>'2026-10-30 15:00:00']],$owner,[],'2026-10-30 20:00:00');eq('Pending',$closed['result'],'no extra overdue time after closing at exact allowance');
eq('Waiting for Paid',$calc(['mode'=>'courier','status'=>'new'],[$new])['result'],'unpaid courier paused');
eq('2026-10-30 11:00:00',$calc(['mode'=>'courier','paid'=>1],[$new,['kind'=>'paid','at'=>'2026-10-30 09:00:00'],$pack])['due_at'],'courier paid later');
eq('2026-10-30 10:00:00',$calc(['mode'=>'courier','paid'=>1],[['kind'=>'paid','at'=>'2026-10-30 07:00:00'],$new,$pack])['due_at'],'paid before New');
eq('Not Measured',$calc(['mode'=>'courier','paid'=>1])['result'],'missing paid evidence');
foreach(['walk-in-customer','Walk in customer','walk_in_customer']as$contact)eq('Excluded',$calc(['contact'=>$contact])['result'],'walk in excluded');
eq('Not Measured',$calc([],[$pack])['result'],'missing New evidence');
eq('Not Measured',$calc(['status'=>'completed'],[$new])['result'],'Complete never substitutes packing');
eq('On Time',$calc([],[$new,$pack,$pack])['result'],'duplicate identical event deduped');
eq('Not Measured',$calc([],[$new,$pack,array_replace($new,['at'=>'2026-10-30 11:00:00'])])['result'],'reopened requires review');
eq(null,$calc([],null,[])['score_outcome'],'unknown responsibility not scored');
$postOwner=[['employee_id'=>7,'effective_from'=>'2026-10-30 10:30:00','effective_to'=>null]];
eq(null,$calc([],[$new,array_replace($pack,['at'=>'2026-10-30 11:00:00'])],$postOwner)['responsible_id'],'late actor not retroactive owner');
eq('Excluded',$calc([],null,null,[['employee_id'=>7,'effective_from'=>'2026-10-30 08:00:00','effective_to'=>'2026-10-30 12:00:00','reason'=>'approved absence']])['result'],'approved absence');
$s=Engine::summary([$on,$late,$over,$pending],7,'2026-10-30 00:00:00','2026-10-30 23:59:59');eq(33.3,$s['score'],'1 on time of 3 determined');eq(2,$s['packed'],'overdue not packed');eq(1,$s['pending'],'pending separate');eq(3,$s['assessed'],'no double counting');
$cross=$e->evaluate($base,[['kind'=>'new','at'=>'2026-10-30 16:00:00'],['kind'=>'packed','at'=>'2026-10-31 09:30:00','actor'=>7]],$owner,[],'2026-10-31 12:00:00');
eq(0,Engine::summary([$cross],7,'2026-10-30 00:00:00','2026-10-30 23:59:59')['packed'],'packing not credited to prior day');
eq(1,Engine::summary([$cross],7,'2026-10-31 00:00:00','2026-10-31 23:59:59')['packed'],'actual packing date');
echo "PASS: $n Packer Orders policy checks\n";
