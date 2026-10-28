<?php
declare(strict_types=1);
require __DIR__.'/product-link-count-regression.php';
$s=['products'=>[$p],'sizes'=>[],'setups'=>[],'materials'=>[],'website'=>[$site],'batch'=>1,'lastSync'=>'2026-10-28 10:00:00'];
$body=['product_id'=>162,'size_conversion_id'=>'','website_key'=>'35276:0','website_confirmed'=>true,'website_origin'=>true,'selling_price_inc_vat'=>'','packaging_mode'=>'none','packaging_confirmed'=>true];
$r=rps_row($body,$s,$settings);
expect_link($r['size_conversion_id']===0&&$r['size_label']==='1 unit','Simple accessory defaults to one item without variations.');
expect_link($r['selling_price_inc_vat']===43.0&&$r['website_price_defaulted'],'Blank unsaved simple-product price uses its own website price.');
expect_link(abs($r['total_cost_ex_vat']-29.4078)<.00000001&&abs($r['profit_per_unit']-(43/1.15-29.4078))<.00000001,'Imported retail and supplier cost remain separate; VAT/profit calculate.');
foreach([680,0] as $manual){$b=$body;$b['selling_price_inc_vat']=$manual;expect_link(rps_row($b,$s,$settings)['selling_price_inc_vat']===(float)$manual,'Entered numeric price is never replaced.');}
$b=$body;$b['selling_price_source']='manual';expect_link(rps_row($b,$s,$settings)['selling_price_inc_vat']===null,'Explicit manual clearing stays blank.');
$b=$body;$b['id']=5;expect_link(rps_row($b,$s,$settings)['selling_price_inc_vat']===null,'Approved historical pricing does not acquire a different price.');
$unknown=$s;$unknown['website'][0]['active_price_inc_vat']=null;expect_link(rps_row($body,$unknown,$settings)['selling_price_inc_vat']===null,'Missing website price stays unknown, not zero.');
$unconfirmed=$body;unset($unconfirmed['website_origin'],$unconfirmed['website_confirmed']);expect_link(rpm_website_defaults($s,$unconfirmed)===$unconfirmed,'No inferred or unconfirmed product link.');
$variable=$s;$variable['website'][0]['product_type']='variation';$variable['website'][0]['variation_id']=1;$b=$body;$b['website_key']='35276:1';expect_link(rps_row($b,$variable,$settings)['selling_price_inc_vat']===null,'Variable-product blank pricing behaviour unchanged.');
$capacity=$s;$capacity['website'][0]['product_name']='Magnetic Stirrer (1kg)';$capacity['products'][0]['woo_product_id']=35276;$capacity['sizes']=[['id'=>5,'label'=>'1kg','measurement_type'=>'weight','base_value'=>1,'base_unit'=>'kg']];$rows=rpd_website_rows(rpm_rows(new LinkEmptyPDO(),$capacity,$settings),$capacity,$settings);expect_link(count($rows)===1&&$rows[0]['size_conversion_id']===0,'Equipment capacity/label is not treated as mass of product contents.');
$volume=$s;$volume['products'][0]['costing_basis']='volume';$volume['products'][0]['base_unit']='L';$volume['website'][0]['product_name']='Hydrosol 500ml';$volume['sizes']=[['id'=>6,'label'=>'500ml','measurement_type'=>'volume','base_value'=>.5,'base_unit'=>'L']];expect_link(rps_row($body,$volume,$settings)['size_conversion_id']===6,'Simple volume product can use its explicit sized product name.');
$before=$s;rpm_website_defaults($s,$body);expect_link($before===$s,'Read-time defaults do not mutate source or website records.');
echo "Simple products: parent price, count/volume sizes, immediate VAT/profit, manual overrides, history and no source writes PASS\n";
