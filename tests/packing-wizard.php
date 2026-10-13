<?php
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../apps/operations/packing-list-action.php');
$start=strpos($source,'function packing_unit_meta(');$end=strpos($source,'function packing_ensure_quantity_text_column(');
eval(substr($source,$start,$end-$start));
foreach(['(50) units in a pack - quantity 20','50(20)','50 units × 20 packs','20 packs of 50 units','50 units per pouch, 20 pouches'] as $text) {
 $r=packing_quantity_plan_stats($text);if($r['totals']['count']!==1000.0||$r['package_count']!==20.0)throw new RuntimeException($text);
}
foreach(['50 units and 20 packs','50 20','50(20) extra 10','20 packs of 50.5 units','50(0)','100g(-1)','label 49','50(20),','10000000000000000 units'] as $text)if(packing_quantity_plan_stats($text)['size_count']!==0)throw new RuntimeException('Accepted '.$text);
$row=['item_name'=>'Tea','unit'=>'units','received_weight'=>'1000','quantity_planned'=>'50(20)','priority'=>'medium'];
if(!packing_invoice_allocation_validation($row)['valid'])throw new RuntimeException('Balanced');
foreach(['-1000','1000 and 20','1.5'] as $received)if(packing_invoice_allocation_validation(array_merge($row,['received_weight'=>$received]))['valid'])throw new RuntimeException('Invalid received stock');
foreach([-1,0.5,'bad'] as $remainder)if(packing_invoice_allocation_validation(array_merge($row,['bulk_remainder'=>$remainder]))['valid'])throw new RuntimeException('Invalid bulk remainder');
foreach(['50(25)','50(15)'] as $plan)if(packing_invoice_allocation_validation(array_merge($row,['quantity_planned'=>$plan]))['valid'])throw new RuntimeException($plan);
if(!packing_invoice_allocation_validation(array_merge($row,['quantity_planned'=>'50(10),25(20)']))['valid'])throw new RuntimeException('Mixed sizes');
if(packing_workload_score('1000units','50(20)','medium')!==22.5)throw new RuntimeException('Workload');
foreach([['kg','1','100g(10)'],['L','1','250ml(4)']] as [$unit,$received,$plan])if(!packing_invoice_allocation_validation(array_merge($row,['unit'=>$unit,'received_weight'=>$received,'quantity_planned'=>$plan]))['valid'])throw new RuntimeException('Measured allocation');
echo "PASS server explicit grammar, stock balance, physical/package workload and measured-unit regression\n";
