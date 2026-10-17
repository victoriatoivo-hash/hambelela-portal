import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const orders=fs.readFileSync('assets/js/orders-board.js','utf8');
const packing=fs.readFileSync('assets/js/packing-list.js','utf8');
const start=orders.indexOf('  async function runOrderBulkAction(action)');
const end=orders.indexOf('    if (!selectedOrders.size) return;',start);
const prefix=orders.slice(start,end)+'  }';
for(const mobile of [true,false]) {
 const context={mobileOrdersViewport:{matches:mobile},mobileBulkDismissed:false,mobileBulkExpanded:false,updateBulkActionBar(){},clears:0,clearOrderSelection(){context.clears++}};
 vm.createContext(context);vm.runInContext(prefix,context);
 await context.runOrderBulkAction('close');assert.equal(context.clears,mobile?0:1);
 if(mobile){assert.equal(context.mobileBulkDismissed,true);await context.runOrderBulkAction('mobile-reopen');assert.equal(context.mobileBulkDismissed,false);await context.runOrderBulkAction('mobile-actions');assert.equal(context.mobileBulkExpanded,true);}
 await context.runOrderBulkAction('clear-selection');assert.equal(context.clears,mobile?1:2);
}
const from=packing.indexOf('  function restorePackingScrollState('),to=packing.indexOf('\n  let packingToolsData',from);
for(const mobile of [true,false]) {
 let scrolls=0,frames=0,focuses=0;
 const ctx={packingMobileViewport:{matches:mobile},packingInteracting:()=>false,window:{scrollTo(){scrolls++}},requestAnimationFrame(fn){frames++;fn();}};
 vm.createContext(ctx);vm.runInContext(packing.slice(from,to),ctx);
 const container={isConnected:true,scrollLeft:700,scrollTop:25};
 ctx.restorePackingScrollState({windowX:0,windowY:200,container,left:100,top:0},{isConnected:true,focus(opts){assert.equal(opts.preventScroll,true);focuses++}});
 assert.equal(scrolls,mobile?0:1);assert.equal(frames,mobile?0:1);assert.equal(container.scrollLeft,mobile?700:100);assert.equal(focuses,1);
}
console.log('Mobile dismiss/reopen/clear semantics and Packing no vertical/stale scroll restoration passed; desktop behavior retained.');
