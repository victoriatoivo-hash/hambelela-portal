<?php
declare(strict_types=1);
require __DIR__.'/accounts-import-vat-parser.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$text="Transaction Records\nTax Type Tax Year\n";
foreach([
 ['Employee Tax','201 - Assessment(original)','30274935',7,'3,577.45'],
 ['Value Added Tax Import Account','201 - Assessment(original)','30183775',7,'11,190.47'],
 ['Value Added Tax Import Account','129 - VIA payment','0200226304',7,'11,190.47'],
 ['Employee Tax','113 - ETX Tax payment','0200226304',7,'3,577.45'],
 ['Employee Tax','113 - ETX Tax payment','0200226304',6,'3,577.45'],
 ['Value Added Tax','201 - Assessment(original)','30276380',1,'26,004.52'],
 ['Unrecognised account','201 - Assessment(original)','UNKNOWN',7,'1.00'],
]as[$account,$type,$doc,$period,$amount]){
 $text.="$account\n$type Tax $doc 2027 $period 20-10-2026 06-10-2026 06-10-2026 $amount\n";
}
$rows=import_vat_namra_text_rows($text);
check(count($rows)===7,'All accounts retained as separate evidence');
check(array_column($rows,'tax_type')===['Employee Tax','Value Added Tax Import Account','Value Added Tax Import Account','Employee Tax','Employee Tax','Value Added Tax','Unknown'],'Tax accounts preserved including standalone cell labels');
check(array_sum(array_column($rows,'included_in_payable'))===2,'Only VIA principal/payment may enter Import VAT');
check(count(array_unique(array_column($rows,'source_hash')))===7,'Same reference across accounts and P6/P7 must not deduplicate');
check($rows[6]['confidence']==='low' && $rows[6]['match_status']==='needs_review','Unknown account fails closed');
$paye=array_values(array_filter($rows,fn($r)=>namra_row_account($r)==='Employee Tax'));
check(count($paye)===3,'PAYE workflow excludes other accounts');
check($paye[1]['tax_period']==='7' && $paye[2]['tax_period']==='6','P6 and P7 distinct');
check(import_vat_namra_text_rows($text)===$rows,'Repeat parsing is deterministic');
$legacy=$rows[3];$legacy['tax_type']='Value Added Tax Import Account';
check(namra_row_account($legacy)==='Employee Tax','Raw Employee Tax evidence overrides old mislabel');
// Optional private local PDF verification: never copied into repository/CI.
if(isset($argv[1])){
 require dirname(__DIR__).'/vendor/autoload.php';
 $raw=(new Smalot\PdfParser\Parser())->parseFile($argv[1])->getText();
 if(getenv('NAMRA_DEBUG')){foreach(['30274935','30183775','0200226304']as$needle){$at=strpos($raw,$needle);echo substr($raw,max(0,$at-170),650)."\n";}exit;}
 $actual=import_vat_namra_text_rows($raw);
 $matches=array_values(array_filter($actual,fn($r)=>in_array($r['doc_number'],['30274935','30183775','0200226304'],true)));
 foreach($matches as $r)echo json_encode(array_intersect_key($r,array_flip(['tax_type','classification','doc_number','tax_year','tax_period','transaction_amount','included_in_payable'])))."\n";
 check(count(array_filter($matches,fn($r)=>$r['doc_number']==='30274935'&&$r['tax_type']==='Employee Tax'))===1,'Original PDF Employee assessment account');
 check(count(array_filter($matches,fn($r)=>$r['doc_number']==='30183775'&&import_vat_is_import_account($r['tax_type'])))===1,'Original PDF Import VAT assessment account');
 check(count(array_filter($matches,fn($r)=>$r['doc_number']==='0200226304'&&$r['tax_type']==='Employee Tax'))===2,'Original PDF P6/P7 PAYE payments');
 echo 'Original PDF parsed: '.count($actual)." rows\n";
}
echo "NamRA tax-account isolation tests passed\n";
