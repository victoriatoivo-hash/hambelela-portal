<?php
declare(strict_types=1);
require dirname(__DIR__).'/shared/cost-workbook-website-pricing.php';
define('CW_SYNC_FIELDS','id,sku');
$pages=[];
function cw_sync_wc_get(string $path,array $query):array {
    global $pages;$pages[]=$query['page'];$out=[];
    for($i=1;$i<=($query['page']===1?100:3);$i++)$out[]=['id'=>($query['page']-1)*100+$i];return $out;
}
if(count(cwp_variations(99))!==103||$pages!==[1,2])throw new RuntimeException('Variation pagination failed');
if(cwp_vat(['website_tax_status'=>'taxable','website_tax_class'=>''],['retail_vat_rate'=>15])!==['vat_treatment'=>'standard','vat_rate'=>15.0])throw new RuntimeException('Default VAT metadata');
foreach([[],['website_tax_status'=>'none'],['website_tax_status'=>'taxable','website_tax_class'=>'special']] as $site)if(cwp_vat($site,['retail_vat_rate'=>0])!==['vat_treatment'=>'standard','vat_rate'=>15.0])throw new RuntimeException('Owner-confirmed internal VAT policy must apply independently of imported metadata');
echo "Website variation pagination and automatic internal 15% VAT policy PASS\n";
