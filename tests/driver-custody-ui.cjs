const {JSDOM}=require('jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
const dom=new JSDOM('<header class="pagehead"><h1></h1><p></p><div class="actions"></div></header><div id="jobs"></div>',{url:'https://example.test',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window,d=w.document,tick=()=>new Promise(r=>setImmediate(r));
w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};w.setInterval=()=>0;
const j={id:1,order_number:'TEST',source:'hambelela',status:'ready',remaining:{order_goods:0,delivery_fee:0,partner_cod:0},receipts:[],history:[],custody:{tracking_version:1,acceptance:null,handover:null,collection:null,exception:null}},data={jobs:[j],methods:{cash:'Cash'}},calls=[];
const command=async b=>{calls.push(b);};w.eval(fs.readFileSync('assets/js/driver-delivery-ui.js','utf8'));
const open=()=>{w.DriverDeliveryUI.render(data,command,'active');d.querySelector('[data-driver-action=details]').click();};
open();assert.equal(d.querySelector('[data-driver-action=start]'),null);d.querySelector('[data-driver-action=accept]').click();d.querySelector('form button').click();await tick();assert.equal(calls.at(-1).action,'accept');
j.custody.acceptance={};open();assert.match(d.querySelector('dialog').textContent,/Awaiting Front Desk handover/);d.querySelector('[data-driver-close]').click();
j.custody.handover={data:{parcel_count:2}};open();d.querySelector('[data-driver-action=confirm_pickup]').click();assert.match(d.querySelector('form').textContent,/2 parcels/);d.querySelector('form button').click();await tick();assert.equal(calls.at(-1).action,'confirm_pickup');
j.custody.collection={};open();assert.ok(d.querySelector('[data-driver-action=start]'));d.querySelector('[data-driver-close]').click();
j.status='out_for_delivery';open();d.querySelector('[data-driver-action=finish]').click();const f=d.querySelector('form');f.querySelector('[value=already_paid]').click();const before=calls.length;f.querySelector('button').click();await tick();assert.equal(calls.length,before,'missing recipient blocks completion');
f.elements.received_by.value='Synthetic recipient';f.elements.confirmation_method.value='photo_reference';f.querySelector('button').click();await tick();assert.equal(calls.length,before,'missing photo reference blocks completion');
f.elements.proof_reference.value='TEST proof';f.querySelector('button').click();await tick();assert.equal(calls.at(-1).confirmation.received_by,'Synthetic recipient');assert.equal(calls.at(-1).confirmation.amount,'0.00');assert.equal(calls.at(-1).confirmation.method,'already_paid');assert.equal(calls.at(-1).confirmation.proof_reference,'TEST proof');
dom.window.close();console.log('PASS tracked compact-driver acceptance, handover, collection and recipient proof with current payment declaration');
})().catch(e=>{console.error(e);process.exit(1);});
