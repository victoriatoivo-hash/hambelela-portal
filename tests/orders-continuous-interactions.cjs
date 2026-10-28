const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync('assets/js/orders-board.js', 'utf8');
const dom = new JSDOM('<div data-orders-board-scroll><main id="rows"></main></div><div class="portal-sticky-horizontal-scrollbar"></div>');
const document = dom.window.document;
const body = document.querySelector('#rows');
let frameCalls = 0;
const ctx = {
  document, body, Element: dom.window.Element, window: dom.window,
  mobileOrdersViewport: {matches:false}, mobileOrdersScrolling:()=>false,
  expandedGroups:new Set(), groupColours:['olive'], modeLabels:[], paymentLabels:[], statusLabels:[],
  customColumns:[], ordersColumns:[], selectedOrders:new Set(), previousOrderIds:new Set(), hasRenderedOnce:true,
  esc:String, money:String, findText:(_,v)=>v, countValues:()=>({}), optionColourMap:()=>({}),
  packingStyleSummaryBar:()=>'', groupLabel:String, groupCountText:n=>`${n} orders`,
  renderUpdateIconCell:()=>'', buildOrderTaskName:o=>`Order ${o.id}`, orderDisplayDateTime:o=>o.created_at,
  labelCellStyle:()=>'', renderLabelCell:(o,field)=>`<button data-label-field="${field}" data-order-id="${o.id}">Status</button>`,
  renderPaymentBadge:()=>'', renderPaidCell:()=>'', renderPackerCell:()=>'<button data-orders-person-trigger>Employee</button>',
  renderCustomCells:()=>'', columnHeader:()=>'', selectorEsc:String,
  activeOrderMutationCount:0, personPopup:null, labelMenu:null, panel:null,
  livePendingGroupKeys:new Set(), groupKey:o=>o.created_at.slice(0,10), ordersCache:[],
  syncPaymentFilterOptions(){}, visibleOrders:()=>ctx.ordersCache,
  groupedOrders:orders=>Object.groupBy(orders,o=>o.created_at.slice(0,10)),
  updateWorkMetrics(){}, updateFilterBadge(){}, renderMoreFilterChips(){}, bindOrdersColumnResizers(){},
  applyHiddenColumns(){}, updateSelectionBar(){}, pendingOrdersInteractionPosition:null
};
dom.window.requestAnimationFrame = () => {frameCalls++;};
vm.createContext(ctx);
function load(start,end) {vm.runInContext(source.slice(source.indexOf(start),source.indexOf(end)),ctx);}
load('  function getOrdersScrollContainer(', '  function focusInlineEditorAtEnd(');
load('  function renderGroup(', '  function renderOrders(');
load('  function patchLiveOrderGroups(', '  function enhanceOrderTaskCells(');
const pointerStart = source.indexOf("  document.addEventListener('pointerdown', (event) => {", source.indexOf("document.addEventListener('dragend'"));
vm.runInContext(source.slice(pointerStart, source.indexOf("  document.addEventListener('click', async",pointerStart)),ctx);
try {
  const orders=Array.from({length:792},(_,i)=>({id:i+1,created_at:`2026-10-${String(1+i%28).padStart(2,'0')} 09:00:00`,total_amount:100,payment_status:'unpaid'}));
  ctx.ordersCache=orders;
  const groups=ctx.groupedOrders(orders), keys=Object.keys(groups).sort().reverse();
  body.innerHTML=keys.map((key,i)=>ctx.renderGroup(key,groups[key],i)).join('');
  assert.equal(body.querySelectorAll('[data-group-card]').length,28,'every date remains in the list');
  assert.equal(body.querySelectorAll('.monday-order-row').length,0,'collapsed dates do not mount hundreds of hidden editors');
  assert.equal([...body.querySelectorAll('[data-count]')].reduce((n,e)=>n+Number(e.dataset.count),0),792);
  const untouched=body.querySelector('[data-group-card="2026-10-01"]');
  ctx.expandedGroups.add('2026-10-28'); ctx.livePendingGroupKeys.add('2026-10-28');
  ctx.patchLiveOrderGroups(orders,orders,[],new Set());
  assert.equal(body.querySelector('[data-collapse-group="2026-10-28"]').getAttribute('aria-expanded'),'true');
  assert.equal(body.querySelectorAll('.monday-order-row').length,groups['2026-10-28'].length);
  assert.equal(body.querySelector('[data-group-card="2026-10-01"]'),untouched,'opening a date does not replace other dates');
  assert.ok(body.querySelector('[data-label-field="status"]'));
  assert.ok(body.querySelector('[data-orders-person-trigger]'));
  const row=body.querySelector('.monday-order-row');
  const scroll=document.querySelector('[data-orders-board-scroll]');
  scroll.scrollLeft=400;
  ctx.patchLiveOrderGroups(orders,orders,[],new Set());
  assert.equal(body.querySelector('.monday-order-row'),row,'unchanged polling preserves active controls');
  assert.equal(scroll.scrollLeft,400);
  assert.equal(ctx.captureOrdersBoardPositions().length,1,'one desktop scroll surface, not one restoration per date');
  const mirror=document.querySelector('.portal-sticky-horizontal-scrollbar');
  mirror.dispatchEvent(new dom.window.Event('pointerdown',{bubbles:true}));
  assert.equal(ctx.pendingOrdersInteractionPosition,null,'scrollbar drag cannot replay its starting position');
  row.querySelector('[data-label-field="status"]').dispatchEvent(new dom.window.Event('pointerdown',{bubbles:true}));
  assert.equal(ctx.pendingOrdersInteractionPosition.table,scroll,'cell click safeguards still capture the table');
  ctx.restoreOrdersTablePosition(ctx.pendingOrdersInteractionPosition);
  assert.equal(frameCalls,0,'no stale animation frame restores after a subsequent interaction');
  assert.equal(ctx.ordersInteractionInProgress(),false,'unfocused board no longer blocks all live updates');
  row.querySelector('[data-label-field="status"]').focus();
  assert.equal(ctx.ordersInteractionInProgress(),true,'focused employee/status controls defer background changes');
  ctx.expandedGroups.delete('2026-10-28'); ctx.livePendingGroupKeys.add('2026-10-28');
  ctx.patchLiveOrderGroups(orders,orders,[],new Set());
  assert.equal(body.querySelectorAll('.monday-order-row').length,0);
  keys.forEach(key=>{ctx.expandedGroups.add(key);ctx.livePendingGroupKeys.add(key);});
  ctx.patchLiveOrderGroups(orders,orders,[],new Set());
  assert.equal(body.querySelectorAll('.monday-order-row').length,792,'all dates can still open together, with no cap or pagination');
  assert.equal(new Set([...body.querySelectorAll('.monday-order-row')].map(e=>e.dataset.orderId)).size,792);
  assert.match(source,/livePendingGroupKeys\.add\(key\);\s*patchLiveOrderGroups\(ordersCache, ordersCache, \[\], new Set\(\)\);/);
  console.log('PASS: 792-order continuous month, lazy closed dates, isolated date toggles, status/employee controls, stable scroll, unchanged polling and no duplicates');
} finally {dom.window.close();}
