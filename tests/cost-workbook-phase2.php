<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/shared/cost-workbook.php';
require_once dirname(__DIR__).'/shared/cost-workbook-phase2.php';
function same($expected,$actual,string $label):void{if($expected!==$actual)throw new RuntimeException($label.' expected '.var_export($expected,true).' got '.var_export($actual,true));}

$fixed=cw2_invoice_line(['quantity'=>'2','unit_price'=>'100','discount_type'=>'fixed','discount_value'=>'20'],'exclusive','15');
same('20.00',$fixed['discount'],'fixed discount');same('180.00',$fixed['line_subtotal'],'exclusive subtotal');same('27.00',$fixed['vat_amount'],'exclusive VAT');same('207.00',$fixed['line_total'],'exclusive total');
$percent=cw2_invoice_line(['quantity'=>'2','unit_price'=>'100','discount_type'=>'percentage','discount_value'=>'10'],'exclusive','15');
same('20.00',$percent['discount'],'percentage discount');same('27.00',$percent['vat_amount'],'percentage VAT');
$inclusive=cw2_invoice_line(['quantity'=>'1','unit_price'=>'115','discount_type'=>'fixed','discount_value'=>'0'],'inclusive','15');
same('100.00',$inclusive['line_subtotal'],'inclusive net');same('15.00',$inclusive['vat_amount'],'inclusive VAT');same('115.00',$inclusive['line_total'],'inclusive total');
$exempt=cw2_invoice_line(['quantity'=>'2','unit_price'=>'100','discount_type'=>'fixed','discount_value'=>'20'],'exempt','15');same('0.00',$exempt['vat_amount'],'exempt VAT');
$mixed=cw2_invoice_line(['quantity'=>'1','unit_price'=>'100','discount_type'=>'fixed','discount_value'=>'0','vat_treatment'=>'exclusive'],'mixed','15');same('115.00',$mixed['line_total'],'mixed line treatment');
$override=cw2_invoice_line(['quantity'=>'1','unit_price'=>'100','discount_type'=>'fixed','discount_value'=>'0','vat_override_amount'=>'14','vat_override_reason'=>'Documented supplier rounding'],'exclusive','15');same('15.00',$override['calculated_vat_amount'],'preserved calculated VAT');same('14.00',$override['vat_amount'],'VAT override');same('override',$override['vat_source'],'override source');
same('185.19',cw2_convert_to_nad('10','18.519'),'exchange direction');
$lines=[['net_purchase'=>100,'normalized_quantity'=>1000,'allocation_weight_kg'=>2],['net_purchase'=>300,'normalized_quantity'=>3000,'allocation_weight_kg'=>6]];
same([2500,7500],cw2_allocate(10000,$lines,'net_value'),'net allocation');same([2500,7500],cw2_allocate(10000,$lines,'normalized_quantity'),'quantity allocation');same([2500,7500],cw2_allocate(10000,$lines,'weight'),'weight allocation');same([5000,5000],cw2_allocate(10000,$lines,'equal'),'equal allocation');same([3333,6667],cw2_allocate(10000,$lines,'manual',[33.33,66.67]),'manual allocation and residual');
$sale=cw2_sale_size(['normalized_quantity'=>'2000','sale_size'=>'100','wastage_percent'=>'10','landed_cost_per_unit'=>'0.09','packaging_cost'=>'2','label_cost'=>'1','preparation_cost'=>'0.50']);same('1800.000000',$sale['usable_quantity'],'usable quantity');same('18.000000',$sale['theoretical_sale_units'],'sale units');same('12.500000',$sale['complete_cost_per_sale_unit'],'complete cost');
$recommended=cw2_recommended_price('60','40','15','nearest_1');same('115.000000',$recommended['exact'],'recommended exact');same('115.00',$recommended['rounded'],'recommended rounded');
same('100.99',number_format((float)cw2_recommended_price('52.17','40','15','end_99')['rounded'],2,'.',''),'end .99 sanity');
echo "Cost Workbook Phase 2 calculation tests passed.\n";
