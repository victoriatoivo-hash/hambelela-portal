import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync('assets/js/portal.js','utf8');
const start=source.indexOf('      let deliveryPending=false;');
const end=source.indexOf("      toast.querySelector('[data-toast-delivery]')",start);
assert.ok(start>0&&end>start);
let closed=0,navigated='',response,finish,message;
const buttons=[{disabled:false},{disabled:false}];
const context=vm.createContext({notification:{id:41,action_link:'/apps/delivery/driver/index.php?delivery=123'},toastClosed:false,
 toast:{querySelectorAll:()=>buttons,querySelector:()=>message||null,append:el=>message=el},
 document:{createElement:()=>({dataset:{},setAttribute:()=>{}})},
 fetch:async()=>new Promise(resolve=>finish=()=>resolve(response)),URLSearchParams,apiUrl:'/api/notifications.php',
 close:()=>closed++,window:{location:{assign:url=>navigated=url}}
});
vm.runInContext(source.slice(start,end)+'\nvar saved=acknowledgeDelivery(true);',context);
assert.equal(closed,0);assert.equal(navigated,'');assert.equal(buttons[0].disabled,true);
response={ok:false,json:async()=>({ok:false})};finish();await context.saved;
assert.equal(closed,0);assert.equal(navigated,'');assert.match(message.textContent,/retry/i);assert.equal(buttons[0].disabled,false);
vm.runInContext('saved=acknowledgeDelivery(true)',context);
response={ok:true,json:async()=>({ok:true})};finish();await context.saved;
assert.equal(closed,1);assert.equal(navigated,'/apps/delivery/driver/index.php?delivery=123');
assert.match(source,/!isMarketing&&!isDelivery/);
// Keep previous persistent Marketing acknowledgement and shared popup gate fixes.
assert.match(source,/action:'marketing_seen'/);assert.match(source,/PortalNoticeGate\?\.release\('marketing'\)/);
console.log('PASS delivery view waits for saved acknowledgement; failed save shows retry; existing Marketing handling preserved');
