import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source = readFileSync('assets/js/orders-board.js', 'utf8');
const toggleSource = source.slice(source.indexOf('  async function togglePaidCell('), source.indexOf('  function renderGroup('));
function setup(ids = ['1']) {
  const toggles = new Map(ids.map(id => [id, {dataset:{paidToggle:id,paidState:'unpaid'}, disabled:false, attrs:{}, classList:{toggle(){}}, setAttribute(k,v){this.attrs[k]=v;}, removeAttribute(k){delete this.attrs[k];}}]));
  let resolve, reject;
  const request = new Promise((yes,no) => {resolve=yes;reject=no;});
  const notices = [];
  const ctx = {paidUpdatesInProgress:new Set(),paidMutationRevision:0,body:{querySelector(selector){return toggles.get(selector.match(/="(.*?)"/)[1]);}},currentSelectedIdsFor:()=>ids,ordersCache:ids.map(id=>({id,payment_status:'unpaid',payments:[{amount_cents:14500}],financial_payment_status:'partial'})),selectorEsc:String,groupKey:()=> 'date',showPaidFeedback:(...args)=>notices.push(args),updateOrdersField:()=>request,refreshGroupSummaries(){},updateWorkMetrics(){},visibleOrders:()=>[]};
  vm.createContext(ctx); vm.runInContext(toggleSource,ctx);
  return {ctx,toggles,notices,resolve,reject};
}
{
  const t=setup(); const pending=t.ctx.togglePaidCell(t.toggles.get('1'));
  assert.equal(t.toggles.get('1').attrs['aria-busy'],'true');
  assert.equal(t.toggles.get('1').attrs['aria-pressed'],'true');
  assert.match(t.notices.at(-1)[0],/Saving/);
  t.resolve([{id:'1'}]); await pending;
  assert.equal(t.toggles.get('1').attrs['aria-pressed'],'true');
  assert.equal(t.toggles.get('1').disabled,false);
  assert.match(t.notices.at(-1)[0],/saved/);
  assert.equal(t.ctx.ordersCache[0].payments[0].amount_cents,14500);
  assert.equal(t.ctx.ordersCache[0].financial_payment_status,'partial');
}
{
  const t=setup(); const pending=t.ctx.togglePaidCell(t.toggles.get('1'));
  t.reject(new Error('Your session token expired.')); await pending;
  assert.equal(t.toggles.get('1').attrs['aria-pressed'],'false');
  assert.match(t.notices.at(-1)[0],/Couldn’t update Paid status.*session token expired/);
  assert.equal(t.notices.at(-1)[1],true);
  assert.equal(t.ctx.paidUpdatesInProgress.size,0);
}
{
  const t=setup(['1','2']); const pending=t.ctx.togglePaidCell(t.toggles.get('1'));
  assert.equal(t.toggles.get('2').disabled,true);
  await t.ctx.togglePaidCell(t.toggles.get('2')); // Cannot start overlapping bulk save.
  t.resolve([{id:'1'}]); await pending;
  assert.equal(t.toggles.get('1').attrs['aria-pressed'],'true');
  assert.equal(t.toggles.get('2').attrs['aria-pressed'],'false');
  assert.equal(t.toggles.get('2').disabled,false);
  assert.match(t.notices.at(-1)[0],/1 failed/);
}
assert.match(source,/paidUpdatesInProgress.size \|\| paidRevisionAtRequest !== paidMutationRevision/);
assert.ok(source.indexOf('if (paidUpdatesInProgress.size || paidRevisionAtRequest') < source.indexOf('liveCursor = String(payload.cursor'), 'Stale Paid response must be discarded before moving the cursor');
assert.match(source,/notice.firstElementChild.textContent = message/,'Errors must be rendered as text, not HTML');
console.log('Paid UI behavior passed: saving, success, failure, partial bulk, overlapping saves, stale refresh guard.');
