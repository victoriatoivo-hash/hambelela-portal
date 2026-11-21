const assert=require('node:assert/strict'),fs=require('node:fs'),http=require('node:http'),path=require('node:path'),os=require('node:os');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'..');
const html=`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>@font-face{font-family:Jost;src:url('/assets/fonts/jost-variable.woff2')}body{margin:0;padding:16px;box-sizing:border-box;background:#f8f8f4;font:400 10.5px Jost,sans-serif}*{box-sizing:border-box}#order-delivery-pane{max-width:950px;margin:auto}</style><main id="order-delivery-pane"></main><script type="module">import{open}from'/assets/js/order-collection-ui.js';window.openPanel=()=>open({id:123,order_number:'INV-TEST',customer_name:'Test customer',can_edit_payment:true,items:[{quantity:2,product_name:'Test product'}]},()=>window.paymentOpens=(window.paymentOpens||0)+1,document.querySelector('main'));window.openPanel();</script>`;
async function run(){
 const server=http.createServer((req,res)=>{const u=new URL(req.url,'http://localhost');if(u.pathname==='/'){res.setHeader('Content-Type','text/html');res.end(html);return;}const f=path.join(root,u.pathname);if(!f.startsWith(root+path.sep)||!fs.existsSync(f)){res.writeHead(404);res.end();return;}res.setHeader('Content-Type',f.endsWith('.js')?'text/javascript':f.endsWith('.css')?'text/css':'font/woff2');fs.createReadStream(f).pipe(res);});await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const browser=await chromium.launch({...(process.platform==='win32'?{executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'}:{}),headless:true});
 try{
  for(const width of [1100,390,320]){
   const page=await browser.newPage({viewport:{width,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
   let job=null,sends=[],paid=false,delay=false,pollCount=0;
   const summary=()=>({order_number:'INV-TEST',customer_name:'Test customer',payment_method:'Cash',board_paid:paid,total_cents:30400,paid_cents:paid?30400:0,collect_cents:paid?0:30400});
   const quote=()=>({...summary(),customer_mobile:'+264810000000',address:'Test delivery street',customer_instructions_clean:'Please call at gate',verification:paid?'paid':'unpaid'});
   const state=()=>({csrf:'synthetic',can_arrange:true,can_reconcile:true,jobs:job?{'123':job}:{},selected_job:job,order_summary:summary(),packing:{name:'Test Packer',at:'2026-11-20 10:00:00'},drivers:[{id:3,full_name:'Test Driver'}],attempts:job?[job]:[],events:[],receipts:[]});
   await page.route('**/apps/delivery/**',async route=>{const body=route.request().postDataJSON();if(!body){pollCount++;return route.fulfill({json:state()});}if(body.action==='quote')return route.fulfill({json:{result:quote()}});if(body.action==='parcel_collected'){assert.equal(body.confirmed,true);assert.equal(body.parcel_count,undefined);job={...job,handover:{actor:'Test Arranger',created_at:'2026-11-21 09:00:00',data:{parcel_count:7}}};return route.fulfill({json:{ok:true,result:{id:44}}});}assert.equal(body.action,'arrange');sends.push(body);if(delay)await new Promise(r=>setTimeout(r,120));job={id:44,status:'ready',driver_employee_id:3,driver_name:'Test Driver',public_reference:'DEL-TEST',scheduled_date:body.date,customer_mobile:body.mobile,address:body.address,urgent:body.urgent,notes:body.notes,payment_arrangement:body.board_terms,response_status:'Notification Queued',acceptance_status:'Awaiting Driver Acceptance',arrangement:{actor:'Test Arranger',created_at:'2026-11-20 11:00:00'},custody:{tracking_version:1}};return route.fulfill({json:{result:{id:44}}});});
   await page.goto('http://127.0.0.1:'+server.address().port);await page.locator('[name=driver]').waitFor();
   assert.equal(await page.locator('[name=driver]').inputValue(),'','No hard-coded driver');
   assert.equal(await page.locator('[name=notes]').inputValue(),'Please call at gate');
   assert.match(await page.locator('[data-accountability]').innerText(),/Test Packer.*12:00/s,'UTC rendered in Namibia');
   await page.locator('[name=driver]').selectOption('3');await page.locator('[name=address]').fill('Corrected test address');await page.locator('[name=urgent]').check();
   await page.locator('[data-paid]').click();assert.equal(await page.evaluate(()=>window.paymentOpens),1);assert.equal(await page.locator('[data-paid]').isChecked(),false);assert.equal(sends.length,0,'Paid checkbox cannot silently save');
   await page.locator('[type=submit]').click();assert.equal(sends.length,0,'Choose a payment arrangement in the form');assert.equal(await page.locator('dialog').count(),0,'No redundant payment popup');await page.locator('[name=terms]').selectOption('cod');
   await page.screenshot({path:path.join(os.tmpdir(),'arrange-delivery-'+width+'.png'),fullPage:true});
   const layout=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth,heading:getComputedStyle(document.querySelector('h4')).fontSize,weight:getComputedStyle(document.querySelector('textarea')).fontWeight}));assert.equal(layout.overflow,false,'No horizontal overflow '+width);assert.equal(layout.heading,'10.5px');assert.equal(layout.weight,'400');
   delay=true;await page.locator('[type=submit]').click();await page.locator('[data-saved] .oc-next').waitFor();assert.equal(await page.locator('dialog').count(),0,'COD sends directly without popup');assert.equal(sends.length,1);assert.equal(sends[0].board_terms,'cod');assert.equal(sends[0].tracking_version,1);assert.equal(sends[0].urgent,true);assert.equal(sends[0].address,'Corrected test address');assert.match(await page.locator('[data-accountability]').innerText(),/Test Arranger/);
   assert.equal(await page.locator('[name=parcels]').count(),0);assert.equal(await page.locator('[data-pickup]').innerText(),'Confirm');job={...job,acceptance:{id:77,created_at:'2026-11-20 11:01:00'},acceptance_status:'Accepted by Driver',response_status:'Accepted by Driver'};
   await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await page.waitForFunction(()=>document.querySelector('[data-saved]').textContent.includes('Accepted by Driver'));assert.equal(await page.locator('[name=parcels]').count(),0);await page.locator('[data-pickup]').click();await page.waitForFunction(()=>!document.querySelector('[data-pickup]'));assert.ok(!(await page.locator('[data-saved]').innerText()).includes('Parcel count'),'Historical quantity is not displayed');
   await page.evaluate(()=>window.openPanel());assert.equal(await page.locator('[type=submit]').count(),0,'Saved delivery reopened rather than duplicate booking');
   job={...job,status:'cancelled'};await page.evaluate(()=>window.openPanel());assert.equal(await page.locator('.oc-progress .is-current').count(),0,'Cancelled not depicted as awaiting collection');
   job=null;paid=true;await page.evaluate(()=>window.openPanel());await page.locator('[name=driver]').selectOption('3');assert.equal(await page.locator('[data-paid]').isChecked(),true);assert.equal(await page.locator('[name=terms] option:checked').textContent(),'Paid');await page.locator('[type=submit]').click();await page.locator('[data-saved] .oc-next').waitFor();assert.equal(sends.at(-1).board_terms,'verified');assert.equal(await page.locator('dialog').count(),0,'Paid order needs no COD prompt');
   // Untrusted proof text remains text even when delivered history is displayed.
   job={...job,status:'completed',delivered_at:'2026-11-20 12:00:00',delivery_confirmation:{actor:'Test Driver',created_at:'2026-11-20 12:00:00',data:{received_by:'<img src=x onerror="window.xss=1">',confirmation_method:'recipient_confirmation'}}};await page.evaluate(()=>window.openPanel());assert.equal(await page.evaluate(()=>window.xss),undefined);assert.equal(await page.locator('[data-saved] img').count(),0);
   // Exercise the actual shared select overlay and legacy stylesheet.
   job=null;paid=false;await page.evaluate(()=>window.openPanel());
   await page.addStyleTag({url:'/assets/css/portal.css'});
   await page.addScriptTag({content:fs.readFileSync(path.join(root,'assets/js/portal.js'),'utf8').split("window.addEventListener('DOMContentLoaded'")[0]});
   await page.evaluate(()=>window.PortalCustomSelect.initialise(document));
   await page.getByRole('button',{name:'Payment arrangement: Select payment arrangement',exact:true}).click();
   await page.getByRole('option',{name:'Collect on delivery',exact:true}).waitFor();
   const optionStyle=await page.getByRole('option',{name:'Collect on delivery',exact:true}).evaluate(el=>({color:getComputedStyle(el).color,font:getComputedStyle(el).fontFamily,weight:getComputedStyle(el).fontWeight,inside:el.getBoundingClientRect().right<=innerWidth}));
   assert.equal(optionStyle.color,'rgb(28, 33, 24)');assert.match(optionStyle.font,/Jost/);assert.equal(optionStyle.weight,'400');assert.equal(optionStyle.inside,true);
   await page.getByRole('option',{name:'Collect on delivery',exact:true}).click();assert.equal(await page.locator('[name=terms]').inputValue(),'cod');
   assert.deepEqual(errors,[]);await page.close();console.log('PASS desktop/mobile '+width+': payment verification, COD/paid, accountability, cancelled history, safe text, no overflow');
  }
 }finally{await browser.close();server.close();}
}
run().catch(e=>{console.error(e);process.exitCode=1;});
