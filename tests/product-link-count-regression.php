<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));
function current_user():array{return ['id'=>94,'name'=>'Disposable linking test'];}
require BASE_PATH.'/shared/cost-workbook.php';
require BASE_PATH.'/shared/cost-workbook-pricing-master.php';
require BASE_PATH.'/shared/cost-workbook-pricing-autosave.php';
require BASE_PATH.'/shared/cost-workbook-pricing-discovery.php';
$api=file_get_contents(BASE_PATH.'/apps/cost-manager/retail-pricing-api.php');$start=strpos($api,'function rp_reply');$end=strpos($api,'try{$pdo=db()',$start);eval(substr($api,$start,$end-$start));
function expect_link($value,string $message):void{if(!$value)throw new RuntimeException($message);}
class LinkEmptyStatement extends PDOStatement {
 public function __construct(){}
 public function execute($params=null):bool{return true;}
 public function fetchAll($mode=null,...$args):array{return [];}
}
class LinkEmptyPDO extends PDO {
 public function __construct(){}
 public function prepare($query,$options=null):PDOStatement{return new LinkEmptyStatement();}
}
$p=['id'=>162,'product_name'=>'African bath nets','short_code'=>'ABN001','product_category'=>'Accessories','costing_basis'=>'count','base_unit'=>'unit','landed_cost_id'=>162,'landed_status'=>'complete','landed_cost_per_base'=>29.4078,'supplier_cost_per_base'=>29.4078,'transport_cost_per_base'=>0,'supplier_name'=>'Fixture','cost_date'=>'2026-10-28','woo_product_id'=>35276,'woo_variation_id'=>0];
$site=['product_id'=>35276,'variation_id'=>0,'product_type'=>'simple','product_name'=>'Mesh Body Scrubber','variation_name'=>'','attributes'=>'[]','sku'=>'','category'=>'Accessories','active_price_inc_vat'=>43,'stock_quantity'=>17,'manage_stock'=>1,'synced_at'=>'2026-10-28 10:00:00'];
$s=['products'=>[$p],'sizes'=>[],'setups'=>[],'materials'=>[],'website'=>[$site],'batch'=>1,'lastSync'=>'2026-10-28 10:00:00'];$settings=['retail_vat_rate'=>15];
$rows=rpd_website_rows(rpm_rows(new LinkEmptyPDO(),$s,$settings),$s,$settings);
expect_link(count($rows)===1,'A linked simple count product must not produce a supplier placeholder plus a website row.');
expect_link($rows[0]['website_key']==='35276:0'&&$rows[0]['size_conversion_id']===0,'Exact website identity and 1-unit conversion retained.');
$body=['product_id'=>162,'size_conversion_id'=>'0','website_key'=>'35276:0','website_confirmed'=>true,'website_origin'=>true,'selling_price_inc_vat'=>'','packaging_mode'=>'none','packaging_confirmed'=>true];
$r=rps_row($body,$s,$settings);
expect_link($r['product_name']==='Mesh Body Scrubber'&&$r['short_code']==='ABN001','Website name and supplier code retained when website SKU is blank.');
$preview=rpm_preview($s,$settings,$body);expect_link($preview['product_name']===$r['product_name']&&$preview['short_code']===$r['short_code'],'Refreshing or editing a linked row cannot switch it back to the supplier display name.');
expect_link(abs($r['product_cost_per_unit']-29.4078)<.00000001,'Source precision retained.');
expect_link($r['selling_price_inc_vat']===null,'An intentional blank working price is not replaced by website retail.');
$body['selling_price_inc_vat']=43;$r=rps_row($body,$s,$settings);
expect_link(abs($r['profit_per_unit']-(43/1.15-29.4078))<.00000001,'VAT and profit calculations unchanged.');
$s['website'][0]['sku']='MESH-001';expect_link(rps_row($body,$s,$settings)['short_code']==='MESH-001','Nonblank website SKU preserved.');
$s['website']=[];unset($p['woo_product_id']);$s['products']=[$p];$rows=rpm_rows(new LinkEmptyPDO(),$s,$settings);expect_link(count($rows)===1&&!$rows[0]['website_key'],'Unlinked supplier still visible.');
echo "Simple count link: one row, canonical website name, supplier code, precision and unchanged calculations PASS\n";
