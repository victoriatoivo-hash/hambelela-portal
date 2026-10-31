<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/shared/epi/PackerOrdersPreview.php';
use Hambelela\EPI\PackerOrdersPreview as Engine;
$e=new Engine(['2026-11-03','2024-02-29']);
function original(Engine $e,string $start,string $end):int {
    $a=Engine::time($start);$b=Engine::time($end);$sum=0;
    for($day=$a->setTime(0,0);$day<=$b;$day=$day->modify('+1 day')){
        if($w=$e->window($day))$sum+=max(0,min($b->getTimestamp(),$w[1]->getTimestamp())-max($a->getTimestamp(),$w[0]->getTimestamp()));
    }
    return $sum;
}
$pairs=[['2021-01-01 00:00:00','2026-10-31 12:00:00'],['2026-10-31 12:00:00','2021-01-01 00:00:00'],['2026-12-31 16:59:59','2027-01-02 09:00:01'],['2026-10-30T06:00:00Z','2026-10-30T15:00:00Z']];
mt_srand(311026);
for($i=0;$i<300;$i++){
    $a=(new DateTimeImmutable('2021-01-01 00:00:00'))->modify('+'.mt_rand(0,2300).' days')->modify('+'.mt_rand(0,86399).' seconds');
    $b=$a->modify('+'.mt_rand(0,800).' days')->modify('+'.mt_rand(0,86399).' seconds');
    $pairs[]=[$a->format('Y-m-d H:i:s'),$b->format('Y-m-d H:i:s')];
}
foreach($pairs as[$a,$b])if($e->seconds($a,$b)!==original($e,$a,$b))throw new RuntimeException("Calendar mismatch: $a to $b");
$start=microtime(true);for($i=0;$i<100;$i++)original($e,...$pairs[0]);$slow=microtime(true)-$start;
$fastEngine=new Engine(['2026-11-03','2024-02-29']);$start=microtime(true);for($i=0;$i<100;$i++)$fastEngine->seconds(...$pairs[0]);$fast=microtime(true)-$start;
if($fast>=$slow/3)throw new RuntimeException('Calendar optimization did not achieve expected improvement');
printf("PASS: %d calendar parity cases; 100 historical intervals %.3fs -> %.3fs (%.1fx faster)\n",count($pairs),$slow,$fast,$slow/max($fast,0.000001));
