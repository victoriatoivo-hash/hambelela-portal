<?php
declare(strict_types=1);

$root=dirname(__DIR__);define('BASE_PATH',$root);require_once $root.'/shared/cost-workbook.php';
function fail_test(string $message):never{throw new RuntimeException($message);}
function check(bool $condition,string $message):void{if(!$condition)fail_test($message);}
function sql_file(PDO $pdo,string $path):void{$sql=file_get_contents($path);if($sql===false)fail_test('Missing SQL fixture: '.$path);foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $statement){$statement=trim(preg_replace('/^\s*--.*$/m','',$statement));if($statement!=='')$pdo->exec($statement);}}
function rows(PDO $pdo,string $table):array{return $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);}

$dsn=getenv('CW_TEST_DSN')?:'';$user=getenv('CW_TEST_DB_USER')?:'';$password=getenv('CW_TEST_DB_PASSWORD')?:'';
if($dsn===''||$user==='')fail_test('Disposable database environment is required.');
$pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
sql_file($pdo,$root.'/apps/cost-manager/cost-workbook-migration.sql');
sql_file($pdo,$root.'/tests/fixtures/cost-workbook-phase1.sql');
$before=['invoices'=>rows($pdo,'cw_supplier_invoices'),'lines'=>rows($pdo,'cw_supplier_invoice_lines'),'snapshots'=>rows($pdo,'cw_product_snapshots'),'batches'=>rows($pdo,'cw_sync_batches')];
cw_upgrade_phase2_schema_v3($pdo);$afterFirst=['invoices'=>rows($pdo,'cw_supplier_invoices'),'snapshots'=>rows($pdo,'cw_product_snapshots'),'batches'=>rows($pdo,'cw_sync_batches')];
cw_upgrade_phase2_schema_v3($pdo);$afterSecond=['invoices'=>rows($pdo,'cw_supplier_invoices'),'snapshots'=>rows($pdo,'cw_product_snapshots'),'batches'=>rows($pdo,'cw_sync_batches')];
check($before['invoices']===$afterFirst['invoices'],'Phase 1 invoice values changed during migration.');
check($afterFirst===$afterSecond,'Second migration run changed protected fixture values.');
check(count($before['lines'])===(int)$pdo->query('SELECT COUNT(*) FROM cw_supplier_invoice_lines')->fetchColumn(),'Phase 1 line count changed.');
$draft=$pdo->query('SELECT approval_status FROM cw_supplier_invoices WHERE id=1')->fetchColumn();$approved=$pdo->query('SELECT approval_status FROM cw_supplier_invoices WHERE id=2')->fetchColumn();
check($draft==='draft','Synthetic Draft #1 did not remain draft.');check($approved==='approved','Synthetic Draft #2 did not remain approved.');
check((int)$pdo->query('SELECT COUNT(*) FROM cw_product_snapshots WHERE id=7')->fetchColumn()===1,'Synthetic snapshot ID 7 was not preserved.');
$tables=['cw_shipments','cw_shipment_invoice_links','cw_shipment_expenses','cw_shipment_expense_files','cw_landed_calculations','cw_landed_calculation_versions','cw_landed_calculation_lines','cw_sale_size_costs','cw_calculation_product_matches','cw_cost_audit_events'];
foreach($tables as $table){$st=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$st->execute([$table]);check((int)$st->fetchColumn()===1,'Missing Phase 2 table '.$table);}
$unique=$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cw_shipments' AND INDEX_NAME='uniq_cw_shipment_reference' AND NON_UNIQUE=0")->fetchColumn();check((int)$unique===1,'Shipment reference unique constraint is missing.');
$foreign=$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('cw_shipment_invoice_links','cw_landed_calculation_lines')")->fetchColumn();check((int)$foreign>=4,'Required Phase 2 link foreign keys are missing.');
$decimals=$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cw_shipment_expenses','cw_landed_calculation_lines','cw_sale_size_costs') AND COLUMN_NAME LIKE '%amount%' AND DATA_TYPE<>'decimal'")->fetchColumn();check((int)$decimals===0,'A monetary amount column is not DECIMAL.');
try{$pdo->exec("INSERT INTO cw_shipments(shipment_reference,shipment_name,shipment_date,created_by_name) VALUES('DUP-1','Synthetic','2026-01-01','test'),('DUP-1','Synthetic','2026-01-01','test')");fail_test('Duplicate shipment reference was accepted.');}catch(PDOException $e){check($e->getCode()==='23000','Unexpected duplicate-reference failure.');}
check(str_starts_with((string)$before['invoices'][0]['stored_file'],'uploads/cost-workbook/'),'Fixture attachment metadata lost its protected storage classification.');
echo "Cost Workbook Phase 2 migration and preservation tests passed.\n";
