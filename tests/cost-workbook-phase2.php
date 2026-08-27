<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/shared/cost-workbook.php';
require_once dirname(__DIR__).'/shared/cost-workbook-phase2.php';
function same($expected,$actual,string $label):void{if($expected!==$actual)throw new RuntimeException($label.' expected '.var_export($expected,true).' got '.var_export($actual,true));}
function truth(bool $actual,string $label):void{if(!$actual)throw new RuntimeException($label.' expected true');}
function throws(callable $fn,string $label):void{try{$fn();}catch(Throwable $e){return;}throw new RuntimeException($label.' expected an exception');}
function throws_type(callable $fn,string $type,string $label):void{try{$fn();}catch(Throwable $e){same($type,$e::class,$label.' exception type');return;}throw new RuntimeException($label.' expected an exception');}

same(3,cw_round_divide(10,3),'positive divided by positive');
same(-3,cw_round_divide(-10,3),'negative divided by positive');
same(-3,cw_round_divide(10,-3),'positive divided by negative');
same(3,cw_round_divide(-10,-3),'negative divided by negative');
same(0,cw_round_divide(0,7),'zero numerator');
same(37,cw_round_divide(37,1),'denominator of one');
same(0,cw_round_divide(49,100),'value smaller than one unit');
same(1,cw_round_divide(5,10),'exact halfway boundary');
same(0,cw_round_divide(4999,10000),'immediately below halfway');
same(1,cw_round_divide(5001,10000),'immediately above halfway');
same(3000000000000,cw_round_divide(9000000000000,3),'large integer-safe monetary value');
throws_type(fn()=>cw_round_divide(1,0),InvalidArgumentException::class,'division by zero');
$helperSource=file_get_contents(dirname(__DIR__).'/shared/cost-workbook.php');
truth($helperSource!==false,'helper source readable');
preg_match('/function cw_round_divide\([^}]+\}/s',(string)$helperSource,$helperMatch);
truth(isset($helperMatch[0]),'helper source located');
truth(!str_contains($helperMatch[0],'(float)'),'decimal-safe helper has no float conversion');

$fixed=cw2_invoice_line(['quantity'=>'2','unit_price'=>'100','discount_type'=>'fixed','discount_value'=>'20'],'exclusive','15');
same('20.00',$fixed['discount'],'fixed discount');same('180.00',$fixed['line_subtotal'],'exclusive subtotal');same('27.00',$fixed['vat_amount'],'exclusive VAT');same('207.00',$fixed['line_total'],'exclusive total');
$percent=cw2_invoice_line(['quantity'=>'2','unit_price'=>'100','discount_type'=>'percentage','discount_value'=>'10'],'exclusive','15');
same('20.00',$percent['discount'],'percentage discount');same('27.00',$percent['vat_amount'],'percentage VAT');
throws(fn()=>cw2_invoice_line(['quantity'=>'1','unit_price'=>'100','discount_type'=>'both','discount_value'=>'10'],'exclusive','15'),'conflicting discount type');
$inclusive=cw2_invoice_line(['quantity'=>'1','unit_price'=>'115','discount_type'=>'fixed','discount_value'=>'0'],'inclusive','15');
same('100.00',$inclusive['line_subtotal'],'inclusive net');same('15.00',$inclusive['vat_amount'],'inclusive VAT');same('115.00',$inclusive['line_total'],'inclusive total');
$exempt=cw2_invoice_line(['quantity'=>'2','unit_price'=>'100','discount_type'=>'fixed','discount_value'=>'20'],'exempt','15');same('0.00',$exempt['vat_amount'],'exempt VAT');
$mixed=cw2_invoice_line(['quantity'=>'1','unit_price'=>'100','discount_type'=>'fixed','discount_value'=>'0','vat_treatment'=>'exclusive'],'mixed','15');same('115.00',$mixed['line_total'],'mixed line treatment');
$override=cw2_invoice_line(['quantity'=>'1','unit_price'=>'100','discount_type'=>'fixed','discount_value'=>'0','vat_override_amount'=>'14','vat_override_reason'=>'Documented supplier rounding'],'exclusive','15');same('15.00',$override['calculated_vat_amount'],'preserved calculated VAT');same('14.00',$override['vat_amount'],'VAT override');same('override',$override['vat_source'],'override source');
throws(fn()=>cw2_invoice_line(['quantity'=>'1','unit_price'=>'100','discount_type'=>'fixed','discount_value'=>'0','vat_override_amount'=>'14'],'exclusive','15'),'override reason required');
same('185.19',cw2_convert_to_nad('10','18.519'),'exchange direction');
same(['2000.000000','g',null],cw_normalize_quantity('2','kg'),'kg to g');same(['1500.000000','ml',null],cw_normalize_quantity('1.5','L'),'L to ml');same(['24.000000','unit',null],cw_normalize_quantity('2','pack','12'),'pack to unit');
truth(cw_normalize_quantity('2','pack')[2]!==null,'pack size required');truth(cw_normalize_quantity('0','kg')[2]!==null,'zero quantity rejected');truth(cw_normalize_quantity('-1','kg')[2]!==null,'negative quantity rejected');truth(cw_normalize_quantity('1','stone')[2]!==null,'unknown unit rejected');
$lines=[['net_purchase'=>100,'normalized_quantity'=>1000,'allocation_weight_kg'=>2],['net_purchase'=>300,'normalized_quantity'=>3000,'allocation_weight_kg'=>6]];
same([2500,7500],cw2_allocate(10000,$lines,'net_value'),'net allocation');same([2500,7500],cw2_allocate(10000,$lines,'normalized_quantity'),'quantity allocation');same([2500,7500],cw2_allocate(10000,$lines,'weight'),'weight allocation');same([5000,5000],cw2_allocate(10000,$lines,'equal'),'equal allocation');same([3333,6667],cw2_allocate(10000,$lines,'manual',[33.33,66.67]),'manual allocation and residual');
same(10000,array_sum(cw2_allocate(10000,$lines,'manual',[25,75])),'manual monetary/percentage reconciliation');throws(fn()=>cw2_allocate(10000,[['allocation_weight_kg'=>0],['allocation_weight_kg'=>0]],'weight'),'zero weight allocation');
$valueAllocation=[['net_purchase'=>300],['net_purchase'=>200]];same([6000,4000],cw2_allocate(10000,$valueAllocation,'net_value'),'N$100 value allocation');same(0,10000-array_sum(cw2_allocate(10000,$valueAllocation,'net_value')),'allocation reconciliation difference');
$sale=cw2_sale_size(['normalized_quantity'=>'2000','sale_size'=>'100','wastage_percent'=>'10','landed_cost_per_unit'=>'0.09','packaging_cost'=>'2','label_cost'=>'1','preparation_cost'=>'0.50']);same('1800.000000',$sale['usable_quantity'],'usable quantity');same('18.000000',$sale['theoretical_sale_units'],'sale units');same('12.500000',$sale['complete_cost_per_sale_unit'],'complete cost');
$recommended=cw2_recommended_price('60','40','15','nearest_1');same('115.000000',$recommended['exact'],'recommended exact');same('115.00',$recommended['rounded'],'recommended rounded');
same('115.00',cw2_recommended_price('60','40','15','nearest_050')['rounded'],'nearest 0.50');same('115.000000',cw2_recommended_price('60','40','15','exact')['exact'],'VAT-inclusive recommendation');
same('100.99',number_format((float)cw2_recommended_price('52.17','40','15','end_99')['rounded'],2,'.',''),'end .99 sanity');
$profit=cw_calculate('115','60','15','40');same(100,$profit['selling_ex_vat'],'selling ex VAT');same(40,$profit['gross_profit'],'gross profit');same(40,$profit['gross_margin'],'gross margin');same(66.67,$profit['markup'],'markup');same(115,$profit['recommended_price_inc_vat'],'recommended price');
echo "Cost Workbook Phase 2 calculation tests passed.\n";
