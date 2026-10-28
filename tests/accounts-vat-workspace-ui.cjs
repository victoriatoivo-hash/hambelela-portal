const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const css=read('assets/css/accounts-vat-workspace.css').replace(/^@import.*$/mg,''),shared=read('assets/js/accounts-vat-workspace.js');
const directory=path.join(root,'verification/vat-workspace');fs.mkdirSync(directory,{recursive:true});
function markup(type,owner=true){
 let source=read(`apps/accounts/${type}-vat.php`).replace('declare(strict_types=1);','').replace(/^require_once .*;.*$/mg,'').replace(/^include .*;.*$/mg,'').replace(/<\?php include BASE_PATH\.'\/shared\/footer.php'; \?>/g,'');
 const stub=`<?php const BASE_PATH=${JSON.stringify(root.replaceAll('\\','/'))};const BASE_URL='';const APP_NAME='Portal';function accounts_require_input_vat_access(){}function accounts_input_vat_schema_ready(){}function accounts_can($x){return ${owner?'true':'false'};}function accounts_is_owner(){return ${owner?'true':'false'};}function accounts_can_access_workspace(){return true;}function accounts_standard_vat_rate(){return 15;}function accounts_csrf_token(){return 'fixture';}function accounts_historical_capture_start_date(){return '2024-01-01';}function output_vat_require_owner(){}function output_vat_schema_ready(){}function output_vat_month($x){return $x;}function output_vat_csrf_token(){return 'fixture';}$_GET['month']='2026-10';?>`;
 return execFileSync(process.env.PHP_BINARY||'php',[],{input:stub+source,encoding:'utf8'}).replace(/<script[\s\S]*?<\/script>/g,'');
}
const purchase={id:1,purchase_date:'2026-10-05',supplier:'Fixture supplier',description:'Fixture purchase',invoice_reference:'FIXTURE-1',inclusive:115,vat:15,exclusive:100,vat_treatment:'standard',calculation_source:'inclusive',automatic_exclusive:100,entered_by:'Fixture Owner',captured_at:'2026-10-05 10:00:00',review_status:'captured',attachments:[{id:1,name:'fixture.pdf',view_url:'/fixture.pdf',download_url:'/fixture.pdf',can_delete:true}],can_edit:true,can_delete:true,can_view_audit:true};
function inputPayload(month='2026-10') {return {ok:true,standard_vat_rate:15,historical_capture_start_date:'2024-01-01',rows:[purchase],summary:{count:1,inclusive:115,vat:15,exclusive:100,treatments:{standard:15},suppliers:{'Fixture supplier':15},descriptions:{'Fixture purchase':15}},capture_progress:Array.from({length:36},(_,i)=>({month:`${2024+Math.floor(i/12)}-${String(i%12+1).padStart(2,'0')}`,count:i===33?1:0,vat:i===33?15:0,complete:false,capture_status:i===33?'in_progress':'not_started'}))};}
function outputPayload(month='2026-10',status='reconciled'){return {ok:true,data:{month,summary:{orders:1,row_orders:1,total_sales:115,net_sales_excl_vat:100,woo_vat:15,expected_vat:15,difference:0,explained_difference:0,unexplained_difference:0,standard_sales:115},period:{status,final_output_vat:15,adjustment:0,synced_at:'2026-10-28 12:00:00'},period_progress:[{month,status}],orders:[{order_number:123,order_date:month+'-05',order_status:'completed',included:1,treatment:'standard',total_sales_incl_vat:115,woo_vat:15,expected_vat:15,net_sales_excl_vat:100}],source_stale:false}};}
(async()=>{
 for(const type of ['input','output']) {const php=read(`apps/accounts/${type}-vat.php`);assert(!/assets\/css\/accounts(?:-hierarchy)?\.css/.test(php));}
 assert(!/\b(?:Arial|Roboto|Inter|Figtree)\b/.test(css));
 const browser=await chromium.launch({headless:true,...(process.env.CI?{}:{channel:'msedge'})});
 for(const type of ['input','output']){
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));let calls=[];
  await page.route('**/*',async route=>{const url=new URL(route.request().url());if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:'<html></html>'});if(!url.pathname.endsWith(`${type}-vat-api.php`))return route.abort();
   if(route.request().method()==='POST'){calls.push(route.request().postData());return route.fulfill({json:type==='input'?{...inputPayload(),row:purchase}:outputPayload(url.searchParams.get('month')||'2026-10')});}
   if(url.searchParams.get('action')==='audit')return route.fulfill({json:{ok:true,history:[{action_key:'created',actor_name:'Fixture Owner',created_at:'2026-10-05 10:00:00'}]}});
   const month=url.searchParams.get('month')||'2026-10';if(month==='2026-09')await new Promise(r=>setTimeout(r,100));return route.fulfill({json:type==='input'?inputPayload(month):outputPayload(month)});
  });
  await page.goto('http://vat-fixture.test/'); // aborted root, then controlled local markup
  for(const width of [1440,1024,390,320]){
   await page.setViewportSize({width,height:960});await page.setContent(`<html><head><style>body{margin:0}${css}</style></head><body>${markup(type)}</body></html>`);await page.addScriptTag({content:shared});await page.addScriptTag({content:read(`assets/js/${type}-vat.js`)});
   await page.waitForFunction(()=>document.querySelectorAll('.vatx-kpi').length===4);
   assert.equal(await page.locator('.vatx-kpi').count(),4);assert.equal(await page.locator('.vatx-kpi__value').first().textContent(),'1');
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${type} ${width} page overflow`);
   assert.equal(await page.locator('h1').evaluate(e=>getComputedStyle(e).fontSize),width<=600?'22px':'24px');
   assert.match(await page.locator('main').evaluate(e=>getComputedStyle(e).fontFamily),/Jost/);
   await page.evaluate(()=>Promise.all(document.getAnimations().map(a=>a.finished.catch(()=>{}))));
   await page.screenshot({path:path.join(directory,`${type}-${width}.png`),fullPage:true});
   await page.locator('[data-vat-period-open]').click();assert(await page.locator('[data-vat-period-dialog]').isVisible());assert.equal(await page.locator('[data-month-tabs] button').count(),12);
   await page.screenshot({path:path.join(directory,`${type}-period-${width}.png`)});await page.locator('[data-vat-period-close]').click();
   if(type==='input'){
    await page.locator('[data-add-purchase]').first().click();await page.locator('[name="inclusive"]').fill('115');assert.match(await page.locator('[data-vat-preview]').textContent(),/15\.00/);
    await page.locator('[name="vat_treatment"]').selectOption('mixed');await page.locator('[name="inclusive"]').fill('1000');await page.locator('[name="zero_rated_amount"]').fill('200');assert.match(await page.locator('[data-vat-preview]').textContent(),/104\.35/);
    await page.locator('[name="files[]"]').setInputFiles({name:'fixture.txt',mimeType:'text/plain',buffer:Buffer.from('disposable fixture')});assert.match(await page.locator('[data-pending-files]').textContent(),/fixture.txt/);
    await page.locator('[name="supplier"]').fill('Fixture supplier');await page.locator('[name="description"]').fill('Fixture purchase');
    assert(await page.locator('[data-save]').isVisible());assert(await page.locator('[data-save]').evaluate(e=>e.getBoundingClientRect().bottom<=innerHeight));
    if(width<=600)assert.equal(await page.locator('[name="supplier"]').evaluate(e=>getComputedStyle(e).fontSize),'16px');
    await page.screenshot({path:path.join(directory,`${type}-purchase-${width}.png`)});await page.locator('[data-close-purchase]').first().click();await page.locator('[data-dialog]').waitFor({state:'hidden'});
    await page.locator('.vatx-row-menu summary').click();await page.locator('[data-edit]').click();assert.match(await page.locator('[data-existing-evidence]').textContent(),/fixture.pdf/);await page.locator('[data-close-purchase]').first().click();await page.locator('[data-dialog]').waitFor({state:'hidden'});
    await page.locator('[data-audit]').click();await page.locator('[data-audit-dialog]').waitFor({state:'visible'});assert.match(await page.locator('[data-audit-history]').textContent(),/Fixture Owner/);await page.screenshot({path:path.join(directory,`${type}-audit-${width}.png`)});await page.locator('[data-audit-dialog] button[value="cancel"]').first().click();
    await page.locator('[data-delete]').click();assert.match(await page.locator('[data-delete-target]').textContent(),/Fixture supplier/);await page.locator('[data-delete-confirm-dialog] button[value="cancel"]').first().click();
    await page.locator('[data-open-rate-settings]').click();await page.screenshot({path:path.join(directory,`${type}-settings-${width}.png`)});await page.locator('[data-close-rate]').first().click();
    await page.locator('[data-month-complete]').click();assert(await page.locator('.vatx-confirm-dialog').isVisible());await page.locator('.vatx-confirm-dialog button[value="cancel"]').click();
   }else{
    assert.match(await page.locator('[data-reconciliation]').textContent(),/Final adjusted Output VAT/);await page.locator('[data-open-adjust]').click();await page.locator('[name="amount"]').fill('5');assert.match(await page.locator('[data-adjust-preview]').textContent(),/20\.00/);await page.screenshot({path:path.join(directory,`${type}-adjustment-${width}.png`)});await page.locator('[data-close]').first().click();
    await page.locator('[data-complete]').click();assert(await page.locator('.vatx-confirm-dialog').isVisible());await page.screenshot({path:path.join(directory,`${type}-complete-${width}.png`)});await page.locator('.vatx-confirm-dialog button[value="cancel"]').click();
   }
   assert.equal(calls.length,0,'Preview and cancelled confirmation must not mutate financial records');assert.deepEqual(errors,[]);
   console.log(`${type} ${width}px structure, typography, period picker, drawers and safe confirmations PASS`);
  }
  await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await page.locator('.vatx-kpi').first().evaluate(e=>getComputedStyle(e).animationName),'none');await page.close();
 }
 const front=markup('input',false);assert(!front.includes('data-rate-dialog'));assert(!front.includes('data-history-toolbar'));
 await browser.close();console.log('Financial source unchanged; Owner-only secondary controls and reduced motion PASS');
})().catch(e=>{console.error(e);process.exit(1)});
