const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync('assets/js/orders-board.js','utf8');
const css=fs.readFileSync('assets/css/orders-list-selection.css','utf8');
const dom=new JSDOM('<main></main>');
const ctx={document:dom.window.document,window:dom.window,page:dom.window.document.querySelector('main'),
 selectionExpanded:false,mobileBulkExpanded:false,mobileBulkDismissed:false,mobileBulkSelectionKey:'',
 selectedOrders:new Set(['1']),selectedOrderRecords:new Map([['1',{selection_money:{total_cents:44000,paid_cents:44000,outstanding_cents:0,payment_verified:true}}]]),
 selectionReadError:'',currentUser:{can_bulk_manage:false,can_move_to_trash:false}};
vm.createContext(ctx);
vm.runInContext(source.slice(source.indexOf('  const selectionCurrency ='),source.indexOf('  async function refreshSelectedOrderRecords()')),ctx);
vm.runInContext(source.slice(source.indexOf('  function ensureBulkActionBar()'),source.indexOf('  async function runOrderBulkAction(')),ctx);
try {
 ctx.updateBulkActionBar();
 const bar=ctx.document.querySelector('#orders-bulk-action-bar');
 assert.equal(bar.hidden,false);
 assert.match(bar.querySelector('[data-selection-total]').textContent,/N\$440\.00/);
 assert.equal(bar.querySelector('[data-selection-toggle]').getAttribute('aria-expanded'),'false');
 assert.ok(bar.querySelector('[data-selection-breakdown]').hasAttribute('data-collapsed'));
 assert.equal(bar.querySelector('[data-order-bulk-action="clear-selection"]').getAttribute('aria-label'),'Clear selection');
 assert.equal(bar.querySelector('[data-order-bulk-action="delete"]').hidden,true);
 assert.equal(bar.querySelector('[data-order-bulk-action="archive"]').hidden,true);
 assert.equal(bar.querySelector('[data-order-bulk-action="export"]').hidden,false);
 ctx.selectionExpanded=true;ctx.updateBulkActionBar();
 assert.equal(bar.querySelector('[data-selection-toggle]').getAttribute('aria-expanded'),'true');
 assert.equal(bar.querySelector('[data-selection-breakdown]').hasAttribute('data-collapsed'),false);
 ctx.selectionReadError='Could not verify selected payments';ctx.renderSelectionMoney(bar);
 assert.ok(bar.classList.contains('has-unverified-selection'));
 assert.match(bar.querySelector('[data-selection-paid]').textContent,/Not verified/);
 ctx.mobileBulkExpanded=true;ctx.updateBulkActionBar();assert.ok(bar.classList.contains('is-mobile-expanded'));
 ctx.mobileBulkDismissed=true;ctx.updateBulkActionBar();assert.ok(bar.classList.contains('is-mobile-dismissed'));
 ctx.selectedOrders.clear();ctx.updateBulkActionBar();assert.equal(bar.hidden,true);
 assert.match(css,/width:560px;max-width:calc\(100vw - 32px\)/);
 assert.match(css,/left:12px!important;right:12px!important;width:auto!important;max-width:none!important;transform:none!important/,'mobile position wins over the legacy toolbar rule');
 assert.match(css,/has-unverified-selection \.orders-selection-meta\[data-collapsed\]\{display:flex\}/);
 assert.match(css,/prefers-reduced-motion/);
 console.log('PASS: compact selection toolbar, verified totals, expandable details, visible errors, clear control, permission restrictions and mobile states');
} finally {dom.window.close();}
