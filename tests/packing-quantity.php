<?php
declare(strict_types=1);
// Load pure parser functions without bootstrapping live routes or touching records.
$source=file_get_contents(__DIR__.'/../apps/operations/packing-list-action.php');
$start=strpos($source,'function packing_unit_meta(');$end=strpos($source,'function packing_received_stock_base(',$start);
eval(substr($source,$start,$end-$start));
foreach(['units','pcs','pieces','labels','bottles','jars','packs','individual items'] as $unit){$r=packing_quantity_plan_stats('49 '.$unit);if($r['totals']['count']!==49.0||$r['package_count']!==49.0)throw new RuntimeException($unit);echo "PASS 49 $unit\n";}
foreach(['Apply labels to all 49 units','label 49','49 labels please','100g(10) and label 49','49.5 units','-49 units','100g(0)'] as $bad){if(packing_quantity_plan_stats($bad)['size_count']!==0)throw new RuntimeException($bad);echo "PASS rejected instruction/invalid quantity: $bad\n";}
if(packing_quantity_plan_stats('100g(10)')['totals']['weight']!==1000.0)throw new RuntimeException('Weight conversion');
if(packing_quantity_plan_stats('250ml x4')['totals']['volume']!==1000.0)throw new RuntimeException('Volume conversion');
if(packing_quantity_plan_stats('49')['totals']['count']!==49.0)throw new RuntimeException('Plain count in quantity field');
if(packing_quantity_plan_stats('20(1kg) 250g(60) 500g(30)')['totals']['weight']!==50000.0)throw new RuntimeException('Legacy measured allocation');
$notes=packing_instructions_notes('Existing notes',['packing_action'=>'apply_labels','labelling_instructions'=>"Apply labels to all 49 units\nKeep upright"]);
if(strpos($notes,'Existing notes')!==0||strpos($notes,'Apply labels')===false)throw new RuntimeException('Instructions preserved');
$notes=packing_instructions_notes($notes,['packing_action'=>'repack','repacking_instructions'=>'1 kg into ten 100 g']);
if(substr_count($notes,'[Packing instructions v1]')!==1)throw new RuntimeException('Duplicate instruction metadata');
echo "PASS compatible repacking, plain count and separate instruction metadata\n";
