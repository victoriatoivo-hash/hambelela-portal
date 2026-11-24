<?php
declare(strict_types=1);
// The invoice client sends canonical size(count) parts for both the source and
// the reviewed task splits. Compare per size, not only total weight or volume.
function packing_monthly_sizes(string $plan):array {
    $sizes=[];
    foreach(explode(',',$plan) as $part){
        if(!preg_match('/^\s*(\d+(?:\.\d+)?)\s*([a-z]+)\((\d+)\)\s*$/iD',$part,$m)
            ||(float)$m[1]<=0||(int)$m[3]<=0)throw new RuntimeException('Review the pack sizes on this invoice.');
        $key=$m[1].':'.strtolower($m[2]);$sizes[$key]=($sizes[$key]??0)+(int)$m[3];
    }
    ksort($sizes);return $sizes;
}
function packing_validate_monthly_split(array $source,array $rows):void {
    $actual=[];$owners=[];
    foreach($rows as $row){
        $line=(int)($row['invoice_source_line']??0)-1;$origin=$source[$line]??null;
        if(!$origin||$row['item_name']!==$origin['item_name']||$row['unit']!==$origin['unit'])throw new RuntimeException('The allocation no longer matches its invoice line.');
        $product=mb_strtolower(preg_replace('/\s+/u',' ',trim((string)$origin['item_name'])),'UTF-8');
        $owner=(int)($row['assigned_employee_id']??0);
        if($product===''||$owner<=0||isset($owners[$product])&&$owners[$product]!==$owner)
            throw new RuntimeException('Allocate each product and all its size variations to one packer. Reopen the allocation review.');
        $owners[$product]=$owner;
        foreach(packing_monthly_sizes((string)$row['quantity_planned']) as $k=>$v)$actual[$line]['packs'][$k]=($actual[$line]['packs'][$k]??0)+$v;
        $actual[$line]['bulk']=($actual[$line]['bulk']??0)+(float)($row['bulk_remainder']??0);
    }
    foreach($source as $i=>$row){
        $sizes=$actual[$i]['packs']??[];ksort($sizes);
        if(!packing_invoice_allocation_validation($row)['valid']||$sizes!==packing_monthly_sizes((string)$row['quantity_planned'])
            ||abs(($actual[$i]['bulk']??0)-(float)$row['bulk_remainder'])>0.00000001)
            throw new RuntimeException('The split must preserve the received quantity, pack sizes and remainder for each invoice line.');
    }
}
