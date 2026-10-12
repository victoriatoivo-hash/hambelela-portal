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
const dialogs=[];
class Element{
 constructor(tag){this.tag=tag;this.dataset={};this.style={};this.events={};this.children=[];}
 setAttribute(){} addEventListener(type,fn){this.events[type]=fn;}
 append(...children){this.children.push(...children);} focus(){} remove(){this.removed=true;}
 showModal(){dialogs.push(this);} close(){this.events.close();}
}
const confirmContext=vm.createContext({document:{createElement:t=>new Element(t),body:{append(){}},addEventListener(){}},window:{dispatchEvent(){}},Event:class{},source:()=> 'accounts',location:{pathname:'/apps/accounts/'}});
vm.runInContext(ui.slice(ui.indexOf('const pageModule='),ui.indexOf('window.PortalNotificationUI=')),confirmContext);
const first=confirmContext.window.PortalNotice.confirm('Cancel test'),second=confirmContext.window.PortalNotice.confirm('Approve test');
await Promise.resolve();assert.equal(dialogs.length,1);
dialogs[0].children.at(-1).children[0].events.click();assert.equal(await first,false);
await Promise.resolve();assert.equal(dialogs.length,2);
dialogs[1].children.at(-1).children[1].events.click();assert.equal(await second,true);
const escapeResult=confirmContext.window.PortalNotice.confirm('Escape test');await Promise.resolve();dialogs[2].close();assert.equal(await escapeResult,false);
console.log('PASS confirmation cancellation, approval, Escape and sequential queue');
