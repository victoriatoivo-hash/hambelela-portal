import fs from 'node:fs';import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const bridge=read('shared/epi/BookkeepingActivityBridge.php'),service=read('shared/epi/BookkeepingPerformance.php'),ledger=read('apps/operations/bookkeeping.php'),migration=read('operations-epi-bookkeeping-performance-migration.sql'),api=read('apps/operations/epi-bookkeeping-performance-data.php');
assert.match(bridge,/hambelela_cashbook_log/);assert.match(bridge,/candidate_only/);assert.match(bridge,/epi-bookkeeping\.log/);
assert.match(ledger,/BookkeepingActivityBridge::record/);assert.match(ledger,/That date already has an opening balance/);
for(const method of ['getSummary','getEmployeeSummary','getOrderReconciliation','getCashEntryCompliance','getDailyReconciliation','getVarianceSummary','getDepositPerformance','getOutstandingRisk','getEvidence','getTimeline'])assert.match(service,new RegExp(method));
assert.match(service,/amount_cents/);assert.match(service,/integer cents/);assert.match(service,/amount_date_customer_candidate/);assert.match(service,/pending_review/);assert.match(service,/normalRef/);assert.match(service,/description.*preg_match|preg_match.*description/);
assert.match(migration,/epi_bookkeeping_match_reviews/);assert.match(migration,/epi_bookkeeping_exceptions/);assert.match(migration,/bookkeeping_deposit_schedule/);
assert.match(api,/owner_admin/);assert.doesNotMatch(service,/score_value|final_score/);console.log('Phase 6 static contracts passed.');
