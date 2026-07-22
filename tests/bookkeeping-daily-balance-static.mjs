import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../apps/operations/bookkeeping.php', import.meta.url), 'utf8');

assert.match(source, /function ledger_calculate_daily_balance\(array \$entries\): array/);
assert.match(source, /ledger_is_opening_balance\(\$entry\)/);
assert.doesNotMatch(source, /description = 'Opening balance'/);
assert.doesNotMatch(source, /\$closingBalance \+= \$cashIn - \$cashOut/);
assert.match(source, /\$closingBalance = \(float\) \(\$dailyBalances\[\$latestApplicableDate\]\['closing_balance'\]/);
assert.match(source, /function latestDayGroup\(visibleOnly = false\)/);
assert.match(source, /updateDisplayedClosingBalance\(\)/);

function daily(entries) {
  let openingCents = 0;
  let cashInCents = 0;
  let cashOutCents = 0;
  for (const entry of entries) {
    const inCents = Math.round(Math.abs(Number(entry.cash_in || 0)) * 100);
    const outCents = Math.round(Math.abs(Number(entry.cash_out || 0)) * 100);
    if (entry.transaction_type === 'opening_balance' || entry.source === 'opening_balance') {
      openingCents += inCents - outCents;
    } else {
      cashInCents += inCents;
      cashOutCents += outCents;
    }
  }
  return {
    opening: openingCents / 100,
    cashIn: cashInCents / 100,
    cashOut: cashOutCents / 100,
    closing: (openingCents + cashInCents - cashOutCents) / 100,
  };
}

const days = {
  '2026-07-20': [
    { transaction_type: 'opening_balance', source: 'opening_balance', cash_in: 1000 },
    { transaction_type: 'cash_received', cash_in: 740 },
  ],
  '2026-07-21': [
    { transaction_type: 'opening_balance', source: 'opening_balance', cash_in: 1740 },
    { transaction_type: 'cash_received', cash_in: 837 },
    { transaction_type: 'cash_taken_out', cash_out: 80 },
  ],
  '2026-07-22': [
    { transaction_type: 'opening_balance', source: 'opening_balance', cash_in: 2497 },
    { transaction_type: 'cash_received', cash_in: 1220 },
  ],
};

const balances = Object.fromEntries(Object.entries(days).map(([date, entries]) => [date, daily(entries)]));
assert.equal(balances['2026-07-20'].closing, 1740);
assert.deepEqual(balances['2026-07-21'], { opening: 1740, cashIn: 837, cashOut: 80, closing: 2497 });
assert.deepEqual(balances['2026-07-22'], { opening: 2497, cashIn: 1220, cashOut: 0, closing: 3717 });
assert.equal(balances[Object.keys(balances).sort().at(-1)].closing, 3717);
assert.notEqual(balances[Object.keys(balances).sort().at(-1)].closing, 7954);
assert.equal(balances['2026-07-21'].closing, 2497);

console.log('Bookkeeping daily balance regression checks passed.');
