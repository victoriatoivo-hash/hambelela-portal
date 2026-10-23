<?php
declare(strict_types=1);

function cwp_snapshot_install(PDO $pdo):void {
    $columns=array_column($pdo->query('SHOW COLUMNS FROM cw_product_snapshots')->fetchAll(PDO::FETCH_ASSOC),'Field');
    foreach(['website_tax_status','website_tax_class'] as $name)if(!in_array($name,$columns,true))$pdo->exec('ALTER TABLE cw_product_snapshots ADD COLUMN '.$name.' VARCHAR(100) NULL');
}
function cwp_variations(int $productId):array {
    $all=[];$page=1;
    do{$part=cw_sync_wc_get('products/'.$productId.'/variations',['page'=>$page++,'per_page'=>100,'status'=>'any','_fields'=>CW_SYNC_FIELDS.',tax_status,tax_class']);foreach($part as $v)$all[(int)$v['id']]=$v;}while(count($part)===100);
    return array_values($all);
}
function cwp_snapshot_tax(PDO $pdo,int $batch,int $pid,int $vid,array $item,array $parent):void {
    $status=$item['tax_status']??$parent['tax_status']??null;
    $class=$item['tax_class']??null;if($class===null||$class==='parent')$class=$parent['tax_class']??null;
    $pdo->prepare('UPDATE cw_product_snapshots SET website_tax_status=?,website_tax_class=? WHERE sync_batch_id=? AND product_id=? AND variation_id=?')->execute([$status,$class,$batch,$pid,$vid]);
}
function cwp_vat(array $site,array $settings):array {
    if(($site['website_tax_status']??'')==='none')return ['vat_treatment'=>'exempt','vat_rate'=>0];
    // Custom tax classes must be explicitly confirmed, not treated as the default rate.
    if(($site['website_tax_status']??'')==='taxable'&&isset($site['website_tax_class'])&&$site['website_tax_class']===''&&is_numeric($settings['retail_vat_rate']??null))return ['vat_treatment'=>'standard','vat_rate'=>(float)$settings['retail_vat_rate']];
    return ['vat_treatment'=>''];
}
