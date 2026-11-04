<?php
require __DIR__.'/../shared/input-vat-month-export.php';
function check($condition,$message) { if (!$condition) throw new RuntimeException($message); }
check(iv_export_period('2026-12')[1]==='2027-01-01','Year rollover');
check(iv_export_period('2024-02')[1]==='2024-03-01','Leap month');
foreach (['','2026-13','2026-00','2026-1','2026-10 OR 1=1'] as $bad) {
    try { iv_export_period($bad); throw new RuntimeException('Accepted bad month'); } catch (InvalidArgumentException $ok) {}
}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE accounts_input_vat_purchases (id INTEGER PRIMARY KEY, purchase_date TEXT, supplier TEXT, invoice_reference TEXT, description TEXT, amount_incl_vat DECIMAL, vat_amount DECIMAL, amount_excl_vat DECIMAL, vat_treatment TEXT, vat_rate DECIMAL, vat_rate_used DECIMAL, review_status TEXT, created_by_name TEXT, created_at TEXT, deleted_at TEXT)');
$insert=$pdo->prepare('INSERT INTO accounts_input_vat_purchases VALUES (?, ?, ?, ?, ?, 115, 15, 100, ?, 14, 15, ?, ?, ?, ?)');
foreach (['2026-09-30','2026-10-01','2026-10-31','2026-11-01','2026-10-15'] as $i=>$date) $insert->execute([$i+1,$date,'=1+1','00123','Supplier & <items>','standard',$i===2?'needs_review':'captured','Fixture owner',$date.' 10:00:00',$i===4?$date:null]);
// More than a page of records must still export.
for($i=6;$i<=125;$i++)$insert->execute([$i,'2026-10-20','Fixture','REF','Saved item','standard','captured','Fixture owner','2026-11-01 10:00:00',null]);
$rows=iv_export_rows($pdo,'2026-10');
check(count($rows)===122,'All October records, including records captured later, excluding deleted');
check($rows[0]['purchase_date']==='2026-10-01' && end($rows)['purchase_date']==='2026-10-31','Full month inclusive boundaries');
check((float)$rows[0]['vat_rate']===15.0,'Use saved effective VAT rate');
check(count(iv_export_rows($pdo,'2026-11'))===1,'No October leakage into November');
$dir=__DIR__.'/../verification/vat-export';if(!is_dir($dir))mkdir($dir,0777,true);
foreach (['2026-10'=>$rows,'2026-08'=>[]] as $month=>$data) {
    $file=iv_export_xlsx($month,$data);rename($file,$dir.'/'.$month.'.xlsx');
}
try { iv_export_xlsx('2026-11',$rows);throw new RuntimeException('Cross-month workbook accepted'); } catch (RuntimeException $e) {check(str_contains($e->getMessage(),'outside'),'Cross-month guard');}
$endpoint=file_get_contents(__DIR__.'/../apps/accounts/input-vat-export.php');
check(strpos($endpoint,'accounts_require_input_vat_access();')<strpos($endpoint,'iv_export_rows(db()'),'Existing permission guard before data');
echo "Monthly query, date boundaries, all rows, deletion exclusion, effective VAT rate, workbook and permissions PASS\n";
