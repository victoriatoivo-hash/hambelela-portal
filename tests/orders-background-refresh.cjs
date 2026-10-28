const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('assets/js/orders-board.js','utf8');
const action=fs.readFileSync('apps/operations/orders-board-action.php','utf8');
const data=fs.readFileSync('apps/operations/orders-board-data.php','utf8');
let release,reads=0,imports=0;
const ctx={performance,Date,window:{setTimeout},page:{dataset:{}},refreshInFlight:null,
 manualOrdersSyncInFlight:null,syncInFlight:null,setButtonBusy(){},showError(){},showOrdersToast(){},
 refresh:async(_,options)=>{assert.equal(options.background,true);reads++;},
 syncWebsite:()=>{imports++;ctx.syncInFlight=new Promise(resolve=>{release=resolve;});return ctx.syncInFlight;}
};
vm.createContext(ctx);
vm.runInContext(source.slice(source.indexOf('  async function refreshOrders('),source.indexOf('  function showError(')),ctx);
(async()=>{
 await Promise.race([ctx.refreshOrders({source:'background',syncSource:true,background:true}),new Promise((_,reject)=>setTimeout(()=>reject(new Error('Slow source import blocked board read')),500))]);
 assert.equal(reads,1);assert.equal(imports,1);
 await ctx.refreshOrders({source:'background',syncSource:true,background:true});
 assert.equal(reads,2);assert.equal(imports,1,'no overlapping source imports');release({ok:true});
 const syncStart=action.indexOf("if ($action === 'sync') {");
 const sessionClose=action.indexOf('session_write_close()',syncStart);
 assert.ok(action.indexOf('ops_board_verify_csrf();')<sessionClose,'authentication/CSRF retained before releasing lock');
 assert.ok(sessionClose<action.indexOf('ops_board_run_guarded_sync($date, $force)',syncStart),'release session before network import');
 assert.ok(data.indexOf('$user = current_user();')<data.indexOf('session_write_close()'));
 assert.doesNotMatch(source,/page.classList.add\('is-background-updating'\)/,'background refresh cannot dim the whole page');
 assert.match(source,/const boardPollInterval = 10 \* 1000/,'cross-user live polling retained');
 console.log('PASS: slow source sync does not delay board reads, no overlapping imports, auth/CSRF retained, session locks released, background dimming removed and live cadence preserved');
})().catch(e=>{console.error(e);process.exitCode=1;});
