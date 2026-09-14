import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('../assets/js/packing-list.js', import.meta.url), 'utf8');
const toolbar = readFileSync(new URL('../assets/js/portal-view-bar.js', import.meta.url), 'utf8');
const start = source.indexOf('  function visibleTasks()');
const end = source.indexOf('  function groupKey(', start);
const context = {
  tasks: Array.from({length:289},(_,i)=>({id:i,item_name:`Item ${i}`,packing_status:i%2?'done':'packing',priority:'high',packing_website_confirmed:i%3===0?1:0,assigned_name:'Demo Packer',packer_notes:i===0?'Special note':''})),
  state:{search:'',date:'',priority:'',status:'',person:'',website:''},
  currentUser:{id:1}, statuses:[],priorities:[],monthKey:v=>String(v||'').slice(0,7),normalize:v=>String(v||'').toLowerCase(),
  labelText:(_,v)=>v==='packing'?'In Progress':v,
  packingStatusIsCompleted:v=>v==='done'
};
vm.createContext(context);
vm.runInContext(source.slice(start,end),context);
assert.equal(context.visibleTasks().length,289);
context.state.search='  in progress  ';
assert.equal(context.visibleTasks().length,145);
context.state.search='special note';
assert.equal(context.visibleTasks().length,1);
context.state.search='Demo Packer';
assert.equal(context.visibleTasks().length,289);
context.state.search='';context.state.website='needs_update';
assert.ok(context.visibleTasks().every(row=>row.packing_status==='done'&&!row.packing_website_confirmed));
assert.match(source,/pageSize: 25/);
assert.match(source,/const pageRows = visible\.slice\(/);
assert.match(source,/const groups = pageRows\.reduce/);
assert.match(source,/renderMobileCards\(pageRows\)/);
assert.match(toolbar,/if \(type !== 'packing'\) searchTimer/);
assert.match(source,/applyPackingSearch\(event.target.value\)/, 'Enter commits the Packing data search');
assert.match(source,/input.value = query/, 'Toolbar and source search stay synchronized');
assert.doesNotMatch(toolbar,/popover.classList.add\('orders-compact-filter-popup'\)/, 'Packing filters do not inherit Orders colours');
console.log('Packing search, website filtering and bounded rendering checks passed.');
