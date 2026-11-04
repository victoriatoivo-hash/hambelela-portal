<?php
declare(strict_types=1);

/** Narrow, reversible ledger repair. Never reprocesses the source statement. */
const IV_SEP_KEY = 'sep2026_tax_account_v1';
const IV_SEP_SHA = '217aa330a02c564911952383cd02c803f681d8fd6527a05b4683ea83f9675420';

function iv_sep_rows(PDO $db, string $sql, array $args = [], bool $lock = false): array
{
    if ($lock && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
    $q = $db->prepare($sql); $q->execute($args);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function iv_sep_snapshot(PDO $db, bool $lock = false): array
{
    $state = [];
    foreach ([
        'statement' => 'SELECT * FROM accounts_import_vat_statements WHERE id=8',
        'liability' => 'SELECT * FROM accounts_import_vat_liabilities WHERE id=21',
        'payments' => 'SELECT * FROM accounts_import_vat_payments WHERE import_id=21 ORDER BY id',
        'rows' => 'SELECT * FROM accounts_import_vat_statement_rows WHERE statement_id=8 AND id IN (1018,1020,1021,1022,1023) ORDER BY id',
    ] as $key => $sql) $state[$key] = iv_sep_rows($db, $sql, [], $lock);
    return $state;
}

function iv_sep_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message.' No correction committed.');
}

function iv_sep_money($v): string { return number_format((float)$v, 2, '.', ''); }

function iv_sep_validate(array $s, bool $corrected): void
{
    $st = $s['statement'][0] ?? [];
    $l = $s['liability'][0] ?? [];
    iv_sep_assert(count($s['statement']) === 1 && ($st['sha256'] ?? '') === IV_SEP_SHA && ($st['status'] ?? '') === 'confirmed' && !empty($st['confirmed_at']), 'Source statement differs');
    iv_sep_assert(count($s['liability']) === 1 && ($l['reference'] ?? '') === 'NAMRA-2027-7' && ($l['accounting_period'] ?? '') === '2026-09' && (int)$l['namra_tax_year'] === 2027 && (int)$l['namra_tax_period'] === 7 && (int)$l['source_statement_id'] === 8 && $l['deleted_at'] === null, 'Liability identity differs');
    $amount = $corrected ? '11190.47' : '14767.92';
    iv_sep_assert(iv_sep_money($l['total_due']) === $amount && iv_sep_money($l['import_vat_amount']) === $amount && iv_sep_money($l['duty_amount']) === '0.00' && iv_sep_money($l['other_charge_amount']) === '0.00' && (int)$l['source_statement_row_id'] === ($corrected ? 1023 : 1018), 'Liability amounts or source differ');
    $expected = [
        1018 => [321,'30274935','assessment','3577.45',7,'fe3707d2433f0a7938b61cbeeadb0d505a34f3214d6ac6523c46d9310492e61e'],
        1020 => [323,'0200226304','payment','11190.47',7,'0e5811e8903ec8d0b17a454962dba09c4c9ea00449dd652c073e34d22a7942c3'],
        1021 => [324,'0200226304','payment','3577.45',7,'c244f4f584b6cb8dc99ea088ba66fad7368046004d099b6b80244dd74ae749f7'],
        1022 => [325,'0200226304','payment','3577.45',6,'776610f636a6535597e28a6a53634ff92df561d252df06d799215f60c22daa73'],
        1023 => [326,'30183775','assessment','11190.47',7,'beaa05d4485ea462748c194c627350520ef46dd6ff0d9414db9eac7966509398'],
    ];
    iv_sep_assert(count($s['rows']) === 5, 'Source row count differs');
    foreach ($s['rows'] as $r) {
        [$n,$doc,$kind,$value,$period,$hash] = $expected[(int)$r['id']] ?? [null,null,null,null,null,null];
        iv_sep_assert((int)$r['source_row_number'] === $n && $r['doc_number'] === $doc && $r['classification'] === $kind && iv_sep_money($r['transaction_amount']) === $value && (int)$r['tax_year'] === 2027 && (int)$r['tax_period'] === $period && $r['source_hash'] === $hash, 'Source row '.$r['id'].' differs');
        if (in_array((int)$r['id'], [1018,1021], true)) {
            iv_sep_assert((int)$r['excluded'] === (int)$corrected && (int)$r['included_in_payable'] === (int)!$corrected && ($corrected ? $r['matched_import_id'] === null && $r['tax_type'] === 'Employee Tax' : (int)$r['matched_import_id'] === 21), 'Employee Tax source allocation differs');
        }
    }
    iv_sep_assert(count($s['payments']) === 2, 'Unexpected September payment allocation');
    $sum = 0;
    foreach ($s['payments'] as $p) {
        $valid = (int)$p['id'] === 44;
        iv_sep_assert(in_array((int)$p['id'], [44,45], true) && (int)$p['source_statement_id'] === 8 && (int)$p['source_statement_row_id'] === ($valid ? 1020 : 1021) && $p['reference'] === '0200226304' && $p['payment_date'] === '2026-10-06' && iv_sep_money($p['amount']) === ($valid ? '11190.47' : '3577.45') && (($p['reversed_at'] !== null) === (!$valid && $corrected)), 'Payment identity or reversal state differs');
        if ($p['reversed_at'] === null) $sum += (float)$p['amount'];
    }
    iv_sep_assert(iv_sep_money($sum) === $amount, 'Actual active payment total differs');
}

/** Read and fingerprint protected financial records without exporting their contents. */
function iv_sep_protected(PDO $db, bool $lock = false): array
{
    $queries = [
        'other_liabilities' => 'SELECT * FROM accounts_import_vat_liabilities WHERE id<>21 ORDER BY id',
        'other_payments' => 'SELECT * FROM accounts_import_vat_payments WHERE import_id<>21 ORDER BY id',
        'other_source_rows' => 'SELECT * FROM accounts_import_vat_statement_rows WHERE id NOT IN (1018,1021) ORDER BY id',
        'accounts_paye_rows' => 'SELECT * FROM accounts_paye_rows ORDER BY id',
        'accounts_paye_periods' => 'SELECT * FROM accounts_paye_periods ORDER BY id',
        'accounts_paye_statements' => 'SELECT * FROM accounts_paye_statements ORDER BY id',
        'accounts_import_vat_credit_eligibility' => 'SELECT * FROM accounts_import_vat_credit_eligibility ORDER BY import_id',
        'accounts_vat_return_periods' => 'SELECT * FROM accounts_vat_return_periods ORDER BY id',
        'accounts_vat_reconciliation_snapshots' => 'SELECT * FROM accounts_vat_reconciliation_snapshots ORDER BY id',
        'accounts_vat_reconciliation_filings' => 'SELECT * FROM accounts_vat_reconciliation_filings ORDER BY period_id',
    ];
    $result = [];
    foreach ($queries as $key => $sql) {
        $rows = iv_sep_rows($db, $sql, [], $lock);
        $result[$key] = ['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows))];
    }
    return $result;
}

function iv_sep_filed_guard(PDO $db): void
{
    // September import credit belongs to the Sep–Oct return unless explicitly assigned elsewhere.
    $rows = iv_sep_rows($db, "SELECT p.id FROM accounts_vat_return_periods p WHERE (p.period_code='2026-09' OR p.id IN (SELECT assigned_period_id FROM accounts_import_vat_credit_eligibility WHERE import_id=21)) AND (EXISTS(SELECT 1 FROM accounts_vat_reconciliation_snapshots s WHERE s.period_id=p.id) OR EXISTS(SELECT 1 FROM accounts_vat_reconciliation_filings f WHERE f.period_id=p.id))");
    iv_sep_assert(!$rows, 'A related VAT return has a frozen snapshot or filing; a reviewed amendment is required first');
}

function iv_sep_change(PDO $db, string $mode, array $actor): array
{
    iv_sep_assert(in_array($mode, ['preview','apply','rollback'], true), 'Unsupported correction mode');
    $db->beginTransaction();
    try {
        $before = iv_sep_snapshot($db, true);
        $audit = iv_sep_rows($db, "SELECT * FROM accounts_import_vat_statement_audit WHERE statement_id=8 AND action_key=? ORDER BY id DESC LIMIT 1", [IV_SEP_KEY], true);
        $last = $audit ? json_decode($audit[0]['details_json'], true, 512, JSON_THROW_ON_ERROR) : null;
        $isApplied = $last && $last['operation'] === 'apply';
        iv_sep_validate($before, (bool)$isApplied);
        $protected = iv_sep_protected($db, true);
        $summary = ['assessed'=>iv_sep_money($before['liability'][0]['import_vat_amount']), 'actual_active_payments'=>iv_sep_money(array_sum(array_map(fn($p)=>$p['reversed_at']===null?(float)$p['amount']:0,$before['payments']))),'outstanding'=>'0.00','liability_id'=>21,'valid_payment_id'=>44,'misallocated_payment_id'=>45,'protected'=>$protected];
        if ($mode === 'preview' || ($mode === 'apply' && $isApplied) || ($mode === 'rollback' && $last && !$isApplied)) {
            if (!$isApplied) iv_sep_filed_guard($db);
            $db->commit(); return $summary + ['state'=>$isApplied?'corrected':'uncorrected','no_change'=>true];
        }
        iv_sep_filed_guard($db);
        if ($mode === 'rollback') {
            iv_sep_assert($isApplied && $before === $last['after'], 'Records changed since correction; automatic rollback blocked');
            $restore = $last['before'];
            foreach (['liability'=>['accounts_import_vat_liabilities',['import_vat_amount','total_due','source_statement_row_id','updated_by','updated_at']], 'payments'=>['accounts_import_vat_payments',['reversed_at','reversed_by','reversal_reason']], 'rows'=>['accounts_import_vat_statement_rows',['tax_type','excluded','included_in_payable','matched_import_id','match_status','match_method']]] as $key=>[$table,$columns]) {
                foreach ($restore[$key] as $r) {
                    if ($key==='payments' && (int)$r['id']!==45) continue;
                    if ($key==='rows' && !in_array((int)$r['id'],[1018,1021],true)) continue;
                    $q=$db->prepare('UPDATE '.$table.' SET '.implode(',',array_map(fn($c)=>$c.'=?',$columns)).' WHERE id=?');
                    $q->execute([...array_map(fn($c)=>$r[$c],$columns),$r['id']]);
                }
            }
        } else {
            $q=$db->prepare("UPDATE accounts_import_vat_statement_rows SET tax_type='Employee Tax',excluded=1,included_in_payable=0,matched_import_id=NULL,match_status='excluded',match_method='September account correction; original evidence retained' WHERE statement_id=8 AND id IN (1018,1021)");$q->execute();iv_sep_assert($q->rowCount()===2,'Source update count differs');
            $q=$db->prepare("UPDATE accounts_import_vat_payments SET reversed_at=?,reversed_by=?,reversal_reason='PAYE P7 misallocated to Import VAT; statement 8 page 8; reversible audited correction' WHERE id=45 AND import_id=21 AND reversed_at IS NULL");$q->execute([date('Y-m-d H:i:s'),$actor['id']]);iv_sep_assert($q->rowCount()===1,'Payment update count differs');
            $q=$db->prepare('UPDATE accounts_import_vat_liabilities SET import_vat_amount=11190.47,total_due=11190.47,source_statement_row_id=1023,updated_by=?,updated_at=? WHERE id=21');$q->execute([$actor['id'],date('Y-m-d H:i:s')]);iv_sep_assert($q->rowCount()===1,'Liability update count differs');
        }
        $after=iv_sep_snapshot($db,true);iv_sep_validate($after,$mode==='apply');
        iv_sep_assert(iv_sep_protected($db,true)===$protected,'An unrelated record changed');
        $details=['operation'=>$mode,'before'=>$before,'after'=>$after,'protected'=>$protected,'reason'=>'Original statement 8 page 8 separates Employee Tax P7 from Import VAT; P6 and all PAYE/filing records preserved'];
        $db->prepare('INSERT INTO accounts_import_vat_statement_audit(statement_id,action_key,details_json,actor_id,actor_name) VALUES(8,?,?,?,?)')->execute([IV_SEP_KEY,json_encode($details,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),$actor['id'],$actor['name']]);
        $db->prepare('INSERT INTO accounts_import_vat_audit(import_id,action_key,before_json,after_json,actor_id,actor_name) VALUES(21,?,?,?,?,?)')->execute([IV_SEP_KEY,json_encode($before),json_encode($after),$actor['id'],$actor['name']]);
        $db->commit();
        return ['state'=>$mode==='apply'?'corrected':'rolled_back','no_change'=>false,'assessed'=>$mode==='apply'?'11190.47':'14767.92','actual_active_payments'=>$mode==='apply'?'11190.47':'14767.92','outstanding'=>'0.00','protected_unchanged'=>true];
    } catch (Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
}
