<?php
declare(strict_types=1);
require __DIR__.'/namra-tax-accounts.php';
require dirname(__DIR__).'/shared/accounts-import-vat-september-correction.php';
function fixture():PDO {
 $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $schemas=[
 'accounts_import_vat_statements'=>'id INTEGER PRIMARY KEY,sha256 TEXT,status TEXT,confirmed_at TEXT',
 'accounts_import_vat_liabilities'=>'id INTEGER PRIMARY KEY,reference TEXT,accounting_period TEXT,namra_tax_year INTEGER,namra_tax_period INTEGER,source_statement_id INTEGER,deleted_at TEXT,import_vat_amount REAL,total_due REAL,duty_amount REAL,other_charge_amount REAL,source_statement_row_id INTEGER,updated_by INTEGER,updated_at TEXT',
 'accounts_import_vat_payments'=>'id INTEGER PRIMARY KEY,import_id INTEGER,source_statement_id INTEGER,source_statement_row_id INTEGER,reference TEXT,payment_date TEXT,amount REAL,reversed_at TEXT,reversed_by INTEGER,reversal_reason TEXT',
 'accounts_import_vat_statement_rows'=>'id INTEGER PRIMARY KEY,statement_id INTEGER,source_row_number INTEGER,doc_number TEXT,classification TEXT,transaction_amount REAL,tax_year INTEGER,tax_period INTEGER,source_hash TEXT,excluded INTEGER,included_in_payable INTEGER,matched_import_id INTEGER,tax_type TEXT,match_status TEXT,match_method TEXT',
 'accounts_paye_rows'=>'id INTEGER PRIMARY KEY,tax_year TEXT,tax_period TEXT,reference TEXT,action_date TEXT,classification TEXT,amount REAL,source_hash TEXT',
 'accounts_paye_periods'=>'id INTEGER PRIMARY KEY,note TEXT',
 'accounts_paye_statements'=>'id INTEGER PRIMARY KEY,note TEXT',
 'accounts_import_vat_credit_eligibility'=>'import_id INTEGER PRIMARY KEY,assigned_period_id INTEGER',
 'accounts_vat_return_periods'=>'id INTEGER PRIMARY KEY,period_code TEXT',
 'accounts_vat_reconciliation_snapshots'=>'id INTEGER PRIMARY KEY,period_id INTEGER',
 'accounts_vat_reconciliation_filings'=>'period_id INTEGER PRIMARY KEY',
 'accounts_import_vat_statement_audit'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,statement_id INTEGER,action_key TEXT,details_json TEXT,actor_id INTEGER,actor_name TEXT',
 'accounts_import_vat_audit'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,import_id INTEGER,action_key TEXT,before_json TEXT,after_json TEXT,actor_id INTEGER,actor_name TEXT',
 ];foreach($schemas as$t=>$columns)$db->exec("CREATE TABLE $t($columns)");
 $db->prepare('INSERT INTO accounts_import_vat_statements VALUES(8,?,?,?)')->execute([IV_SEP_SHA,'confirmed','2026-10-29']);
 $db->exec("INSERT INTO accounts_import_vat_liabilities VALUES(21,'NAMRA-2027-7','2026-09',2027,7,8,NULL,14767.92,14767.92,0,0,1018,1,'2026-10-29')");
 $db->exec("INSERT INTO accounts_import_vat_liabilities VALUES(22,'other','2026-08',2027,6,8,NULL,100,100,0,0,0,1,'2026-10-29')");
 $db->exec("INSERT INTO accounts_import_vat_payments VALUES(44,21,8,1020,'0200226304','2026-10-06',11190.47,NULL,NULL,NULL),(45,21,8,1021,'0200226304','2026-10-06',3577.45,NULL,NULL,NULL),(46,22,8,1022,'other','2026-10-06',100,NULL,NULL,NULL)");
 // Synthetic records using the explicitly authorised incident identifiers.
 $rows=[
 [1018,321,'30274935','assessment',3577.45,7,'fe3707d2433f0a7938b61cbeeadb0d505a34f3214d6ac6523c46d9310492e61e'],
 [1020,323,'0200226304','payment',11190.47,7,'0e5811e8903ec8d0b17a454962dba09c4c9ea00449dd652c073e34d22a7942c3'],
 [1021,324,'0200226304','payment',3577.45,7,'c244f4f584b6cb8dc99ea088ba66fad7368046004d099b6b80244dd74ae749f7'],
 [1022,325,'0200226304','payment',3577.45,6,'776610f636a6535597e28a6a53634ff92df561d252df06d799215f60c22daa73'],
 [1023,326,'30183775','assessment',11190.47,7,'beaa05d4485ea462748c194c627350520ef46dd6ff0d9414db9eac7966509398']];
 foreach($rows as[$id,$n,$doc,$class,$amount,$period,$hash])$db->prepare('INSERT INTO accounts_import_vat_statement_rows VALUES(?,8,?,?,?,?,2027,?,?,0,1,21,?,?,?)')->execute([$id,$n,$doc,$class,$amount,$period,$hash,'Value Added Tax Import Account','matched','original']);
 $db->exec("INSERT INTO accounts_paye_rows VALUES(1,'2027','7','0200226304','2026-10-06','payment',3577.45,'legacy-hash'),(2,'2027','6','0200226304','2026-10-06','payment',3577.45,'p6-hash')");
 $db->exec("INSERT INTO accounts_vat_return_periods VALUES(1,'2026-09'),(2,'2026-07')");
 return $db;
}
function mustFail(callable $f,string $message):void{try{$f();}catch(Throwable$e){check(str_contains($e->getMessage(),$message),'Expected '.$message.', got '.$e->getMessage());return;}throw new RuntimeException('Expected rejection: '.$message);}
$actor=['id'=>1,'name'=>'Regression test'];$db=fixture();$before=iv_sep_snapshot($db);$protected=iv_sep_protected($db);
check(iv_sep_change($db,'preview',$actor)['assessed']==='14767.92','Preview');
check(iv_sep_snapshot($db)===$before,'Preview read only');
$result=iv_sep_change($db,'apply',$actor);check($result['actual_active_payments']==='11190.47','Actual active payments corrected');
check(iv_sep_protected($db)===$protected,'P6/PAYE/other periods and filings preserved');
check(iv_sep_change($db,'apply',$actor)['no_change'],'Repeat apply safe');
check((int)$db->query('SELECT COUNT(*) FROM accounts_import_vat_statement_audit')->fetchColumn()===1,'No duplicate audit on retry');
iv_sep_change($db,'rollback',$actor);check(iv_sep_snapshot($db)===$before,'Exact rollback');
check(iv_sep_change($db,'rollback',$actor)['no_change'],'Repeat rollback safe');
iv_sep_change($db,'apply',$actor);check(iv_sep_change($db,'preview',$actor)['state']==='corrected','Reapply after rollback');
$db->exec("UPDATE accounts_import_vat_liabilities SET updated_at='newer change' WHERE id=21");
mustFail(fn()=>iv_sep_change($db,'rollback',$actor),'Records changed');
foreach(['snapshot','filing']as$kind){$db=fixture();$db->exec($kind==='snapshot'?'INSERT INTO accounts_vat_reconciliation_snapshots VALUES(1,1)':'INSERT INTO accounts_vat_reconciliation_filings VALUES(1)');mustFail(fn()=>iv_sep_change($db,'apply',$actor),'frozen snapshot or filing');check(iv_sep_snapshot($db)===$before,'Filed history untouched');}
$db=fixture();$db->exec('INSERT INTO accounts_vat_reconciliation_snapshots VALUES(1,2)');iv_sep_change($db,'apply',$actor);check((int)$db->query('SELECT COUNT(*) FROM accounts_vat_reconciliation_snapshots')->fetchColumn()===1,'Unrelated snapshots preserved');
$db=fixture();$db->exec("UPDATE accounts_import_vat_statement_rows SET source_hash='changed' WHERE id=1021");mustFail(fn()=>iv_sep_change($db,'apply',$actor),'Source row');
$db=fixture();$db->exec("CREATE TRIGGER fail_update BEFORE UPDATE ON accounts_import_vat_liabilities BEGIN SELECT RAISE(ABORT,'injected failure'); END");mustFail(fn()=>iv_sep_change($db,'apply',$actor),'injected failure');check(iv_sep_snapshot($db)===$before,'Partial failure rolled back');
$db=fixture();$r=['source_hash'=>'corrected-hash','tax_year'=>'2027','tax_period'=>'7','doc_number'=>'0200226304','reference'=>'0200226304','action_date'=>'2026-10-06','transaction_amount'=>3577.45];
check(namra_paye_duplicate($db,$r,'payment'),'Legacy PAYE hash dedup');$r['tax_period']='6';check(namra_paye_duplicate($db,$r,'payment'),'P6 dedup separately');$r['tax_period']='8';check(!namra_paye_duplicate($db,$r,'payment'),'Different period not deduped');
echo "Correction: apply/retry/rollback/reapply, protected PAYE/P6, snapshots, failure atomicity and source validation passed\n";
