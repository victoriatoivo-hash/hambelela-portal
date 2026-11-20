const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
const source=fs.readFileSync('assets/js/order-collection-ui.js','utf8').replaceAll('export ','').replaceAll('import.meta.url',JSON.stringify('https://example.invalid/assets/js/order-collection-ui.js'));
const board=fs.readFileSync('assets/js/orders-board.js','utf8');
assert.match(board,/label: 'Packed By'[^\n]+\n[^\n]+label: 'Delivery'/);
const tick=()=>new Promise(r=>setImmediate(r));
async function test(conflict){
 const dom=new JSDOM('<div class="ops-board-page"><section><span data-collection-detail data-collection-order="123"></span></section></div>',{url:'https://example.invalid/apps/operations/orders-board.php',runScripts:'outside-only'});
 const w=dom.window;w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
 w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
 let job,sends=0,release;const get=()=>({jobs:job?{'123':job}:{},can_arrange:true,can_reconcile:true,csrf:'test-only',drivers:[{id:3,full_name:'Test Driver'}],events:[],receipts:[]});
 w.fetch=async(url,opts)=>{
   assert.equal(url.origin,'https://example.invalid');const body=opts.body&&JSON.parse(opts.body);
   if(!body)return {ok:true,json:async()=>get()};
   if(body.action==='quote')return {ok:true,json:async()=>({result:{collect_cents:12000,total_cents:12000,paid_cents:0,customer_mobile:'0810000000',customer_name:'Test',address:'Test Street',payment_method:'Cash',verification:'synthetic'}})};
   assert.equal(body.action,'arrange');assert.equal(body.order,123);sends++;
   await new Promise(r=>release=r);job={id:44,status:'ready',driver_name:'Test Driver',public_reference:'TEST'};
   return conflict?{ok:false,status:409,json:async()=>({error:'Already arranged',existing_delivery_id:44})}:{ok:true,json:async()=>({result:{id:44}})};
 };
 const api=w.eval(source+';({refresh,open,label})');
 await api.refresh();const order={id:123,order_type:'delivery',items:[{product_name:'Test oil',quantity:2}]};
 assert.match(w.document.body.textContent,/Arrange delivery/);await api.open(order,()=>{});
 const f=w.document.querySelector('form');assert.match(f.textContent,/2 × Test oil/);f.elements.driver.value='3';
 const event={preventDefault(){}};const submit=f.onsubmit(event);await tick();await f.onsubmit(event);assert.equal(sends,1);release();await submit;
 assert.equal(w.document.querySelector('#oc-title').textContent,'Delivery arranged');assert.equal(w.document.querySelector('[type=submit]'),null);
 w.document.querySelector('dialog').close();await api.open(order,()=>{});
 assert.match(w.document.querySelector('dialog').textContent,/Delivery arranged/);assert.equal(w.document.querySelector('[type=submit]'),null);assert.equal(sends,1);
 dom.window.close();
}
(async()=>{await test(false);await test(true);console.log('PASS booking preview, double click, concurrent booking, saved badge and reopen');})().catch(e=>{console.error(e);process.exitCode=1;});
