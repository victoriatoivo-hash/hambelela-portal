<?php
declare(strict_types=1);
require __DIR__.'/../integrations/hambelela-sale-manager/rules.php';
use function Hambelela\Sales\{clean_rule, quote, date_stamp};
$input = ['name'=>'Test sale','scope'=>'category','categories'=>[5],'include'=>[7],'exclude'=>[11],'enabled'=>true,'kind'=>'bundle','amount'=>'180','quantity'=>2,'end'=>'2026-12-01T00:00'];
$r = clean_rule($input);
$now = date_stamp('2026-11-12T20:00');
$row = ['id'=>10,'parent'=>3,'categories'=>[5],'regular'=>10500,'base'=>10500];
function eq($got,$expected) {if ($got !== $expected) throw new RuntimeException(json_encode([$got,$expected]));}
$price = static function($rows,$p,$n,$t) {return quote($rows,$p,$n,$t)['total'];};
foreach ([1=>10500,2=>18000,3=>28500,4=>36000,5=>46500] as $qty=>$total) eq($price([$r],$row,$qty,$now),$total);
eq($price([$r],array_replace($row,['id'=>11]),2,$now),21000);
eq($price([$r],array_replace($row,['categories'=>[],'id'=>7]),2,$now),18000);
eq($price([$r],$row,2,$r['until']),21000);
eq($price([array_replace($r,['enabled'=>false])],$row,2,$now),21000);
$pct=clean_rule(array_replace($input,['scope'=>'all','kind'=>'percent','amount'=>'10']));
eq($price([$pct],$row,1,$now),9450);
eq($price([$pct,$r],$row,2,$now),18000);
eq($price([$pct],array_replace($row,['base'=>9000]),1,$now),9000);
$fixed=clean_rule(array_replace($input,['kind'=>'fixed','amount'=>'78.99','scope'=>'selected','include'=>[3]]));
eq($price([$fixed],$row,1,$now),7899);
eq($price([$fixed],array_replace($row,['parent'=>0]),1,$now),10500);
eq(date_stamp('2026-12-01T00:00'),1796076000);
foreach ([['amount'=>'0'],['quantity'=>0],['end'=>'2026-02-30T04:00'],['scope'=>'selected','include'=>[]],['kind'=>'percent','amount'=>'101']] as $bad) {
    try { clean_rule(array_replace($input,$bad)); } catch (InvalidArgumentException $e) {continue;}
    throw new RuntimeException('Invalid sale accepted');
}
echo "Campaign pricing, scope, overlaps, schedules and validation passed.\n";
