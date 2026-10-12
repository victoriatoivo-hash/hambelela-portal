import fs from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';
const read=p=>fs.readFileSync(p,'utf8');
const ui=read('assets/js/notifications-ui.js'),css=read('assets/css/portal-notices.css'),portal=read('assets/js/portal.js');
for(const color of ['#59694A','#9B477E','#966313','#24796F','#28639B','#52667B'])assert.ok(css.includes(color),color);
assert.match(css,/dialog\.hb-notice-modal\{pointer-events:auto/);
assert.match(css,/env\(safe-area-inset-bottom\)/);
assert.match(css,/prefers-reduced-motion/);
assert.match(css,/Jost,sans-serif/);
assert.match(portal,/badge.hidden=countValue<=0/);
assert.match(portal,/countValue > 99 \? '99\+'/);
assert.match(portal,/toast.dataset.module=notificationSource/);
assert.doesNotMatch(portal,/data-marketing-popup-css/);
assert.match(read('shared/header.php'),/portal-notices.css/);
let open=false,events=0;
const context=vm.createContext({document:{querySelector:()=>open},window:{dispatchEvent:()=>events++},Event:class{}});
vm.runInContext(ui.slice(ui.indexOf('let modalOwner=null;'),ui.indexOf('const pageModule=')),context);
const gate=context.window.PortalNoticeGate;
assert.equal(gate.acquire('marketing'),true);assert.equal(gate.acquire('tasks'),false);
gate.release('tasks');assert.equal(events,0);gate.release('marketing');assert.equal(events,1);
assert.equal(gate.acquire('tasks'),true);gate.release('tasks');open=true;assert.equal(gate.acquire('marketing'),false);
for(const file of ['assets/js/task-assignment-popup.js','assets/js/acknowledgments-popup.js'])assert.match(read(file),/PortalNoticeGate/);
console.log('PASS shared module palette, initial-load CSS, Jost, safe area, unread badge and cross-renderer modal gate');

// A lost claim or an acknowledgement arriving during the claim must release
// the shared gate so Tasks and Acknowledgments are not left blocked.
const claimStart=portal.indexOf('    const showPortalToast = async (notification) => {');
const claimEnd=portal.indexOf('      const container = ensureToastContainer();',claimStart);
for(const scenario of ['lost-claim','acknowledged-during-claim']) {
 let seen=false;
 const claimContext=vm.createContext({
  document:{querySelector:()=>false}, Event:class{},
  window:{dispatchEvent:()=>{}},
  fetch:async()=>{if(scenario==='acknowledged-during-claim')seen=true;return {json:async()=>({claimed:scenario!=='lost-claim'})};},
  apiUrl:'/api/notifications.php', URLSearchParams,
  marketingAcknowledgements:{has:()=>seen}, marketingQueued:new Set([41]),
  marketingModalActive:true,visibleNotificationToasts:1,drainNotificationToasts:()=>{}
 });
 vm.runInContext(ui.slice(ui.indexOf('let modalOwner=null;'),ui.indexOf('const pageModule=')),claimContext);
 assert.equal(claimContext.window.PortalNoticeGate.acquire('marketing'),true);
 vm.runInContext(portal.slice(claimStart,claimEnd)+'}; var finished=showPortalToast({id:41,related_type:"marketing_assignment"});',claimContext);
 await claimContext.finished;
 assert.equal(claimContext.marketingModalActive,false,scenario);
 assert.equal(claimContext.visibleNotificationToasts,0,scenario);
 assert.equal(claimContext.window.PortalNoticeGate.acquire('tasks'),true,scenario);
 claimContext.window.PortalNoticeGate.release('tasks');
 assert.equal(claimContext.window.PortalNoticeGate.acquire('policy'),true,scenario);
}
console.log('PASS failed/stale Marketing claims release Task and Acknowledgment popup gate');
