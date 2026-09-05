<?php
declare(strict_types=1);
function budget_cents($value):?int {
    $v=trim((string)$value);if($v==='')return null;
    if(!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$v))throw new RuntimeException('Costs must be positive amounts with no more than two decimal places.');
    $p=explode('.',$v);return (int)$p[0]*100+(int)str_pad($p[1]??'',2,'0');
}
function budget_summary(array $items):array {
    $cents=0;$missing=0;foreach($items as $item){$cost=budget_cents($item['cost']??'');if($cost===null)$missing++;else$cents+=$cost;}return compact('cents','missing');
}
function budget_validate(array $data):array {
    $kind=(string)($data['kind']??'');$title=trim((string)($data['title']??''));$date=(string)($data['date']??'');
    if(!in_array($kind,['supplier','office'],true)||$title===''||strlen($title)>160)throw new RuntimeException('Choose a type and enter a list name (up to 160 characters).');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new RuntimeException('Choose a valid budget date.');
    $raw=$data['items']??[];if(!is_array($raw)||count($raw)>200)throw new RuntimeException('Use up to 200 items per list.');
    $items=[];foreach($raw as $r){if(!is_array($r))throw new RuntimeException('Invalid item.');$item=trim((string)($r['item']??''));$quantity=trim((string)($r['quantity']??''));$cost=trim((string)($r['cost']??''));if($item===''&&$quantity===''&&$cost==='')continue;if($item===''||$quantity===''||strlen($item)>300||strlen($quantity)>80)throw new RuntimeException('Each row needs an item and quantity (including its unit or size).');budget_cents($cost);$items[]=compact('item','quantity','cost');}
    if(!$items)throw new RuntimeException('Add at least one item.');return compact('kind','title','date','items');
}
