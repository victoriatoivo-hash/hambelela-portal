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

// Execute the actual arrangement form handler, including success UI, rather
// than treating a saved HTTP response as the end of the customer workflow.
const deliverySource=fs.readFileSync('assets/js/delivery-core.js','utf8');
const formStart=deliverySource.indexOf("   $('#dispatch-confirmation').onsubmit=async event=>{");
const formEnd=deliverySource.indexOf("   $('#dispatch-confirmation textarea')",formStart);
for(const savedNotice of [true,false]){
 let toastMessage='',modalClosed=false,error={textContent:'',innerHTML:''},savedPayload;
 const form={reportValidity:()=>true,querySelector:()=>error};
 const elements={'#dispatch-confirmation':form,'#message':{},'#order-search-modal':{close:()=>modalClosed=true},'#search':{},'#results':{}};
 const formContext=vm.createContext({$:selector=>elements[selector],drivers:[{id:17,full_name:'Synthetic Driver'}],
  selectedOrder:{id:100},dispatchQuote:{order_number:'WEB-TEST',verification:'verified'},board:{today:'2026-10-13'},
  FormData:class{*[Symbol.iterator](){yield ['driver','17'];}},
  command:async payload=>{savedPayload=payload;return {ok:true,result:{id:123,driver_notification_saved:savedNotice}};},
  window:{showPortalToast:options=>toastMessage=options.message},esc:value=>String(value)
 });
 vm.runInContext(deliverySource.slice(formStart,formEnd),formContext);
 await form.onsubmit({preventDefault:()=>{},target:form});
 assert.equal(error.innerHTML,'');assert.equal(error.textContent,'');assert.equal(modalClosed,true);
 assert.equal(savedPayload.order,100);assert.equal(savedPayload.driver,'17');
 assert.match(toastMessage,savedNotice?/Driver notification saved/:/queued for retry/);
}
console.log('PASS complete arrangement success handler closes review and accurately reports saved versus queued Driver notice');
