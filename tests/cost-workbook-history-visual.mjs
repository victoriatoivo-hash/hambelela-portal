import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';

const out=process.env.CW_SCREENSHOT_DIR||'artifacts/historical-cost-records';
await mkdir(out,{recursive:true});
const browser=await chromium.launch({headless:true});
let n=0;
const ok=(value,message)=>{n++;assert.ok(value,message);};
const sizes=[[1440,1000],[1024,900],[768,1024],[390,844],[360,800]];

for(const [width,height] of sizes){
  const context=await browser.newContext({viewport:{width,height}});
  const page=await context.newPage();
  const errors=[];
  const failed=[];
  page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
  page.on('pageerror',error=>errors.push(error.message));
  page.on('requestfailed',request=>failed.push(request.url()));
  await page.goto('http://127.0.0.1:8811/apps/cost-manager/historical-cost-records.php');
  await page.waitForLoadState('networkidle');
  ok(await page.getByRole('heading',{name:'Historical Cost Records'}).isVisible(),width+' heading');
  ok(await page.getByText('READ ONLY',{exact:true}).first().isVisible(),width+' badge');
  ok(await page.getByRole('link',{name:'Overview'}).isVisible(),width+' shared navigation');
  ok((await page.locator('body').innerText()).includes('preserved for reference only'),width+' notice');
  ok(!(await page.locator('body').innerText()).includes('Save & Next'),width+' no workflow');
  ok(await page.locator('input[type=file], input[type=number], textarea').count()===0,width+' no mutation input');
  ok(await page.locator('select[name=dataset] option').count()===9,width+' nine sections');
  const metrics=await page.evaluate(()=>({doc:document.documentElement.scrollWidth,client:document.documentElement.clientWidth,table:document.querySelector('.cw-history-table-wrap')?.scrollWidth||0,wrap:document.querySelector('.cw-history-table-wrap')?.clientWidth||0,h1:document.querySelectorAll('h1').length,ids:[...document.querySelectorAll('[id]')].map(element=>element.id)}));
  ok(metrics.doc<=metrics.client+1,width+' no page overflow');
  ok(metrics.table>=metrics.wrap,width+' contained table');
  ok(metrics.h1===1,width+' one h1');
  ok(new Set(metrics.ids).size===metrics.ids.length,width+' unique ids');
  await page.locator('select[name=dataset]').selectOption('raw_materials');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'View records'}).click()]);
  ok((await page.locator('body').innerText()).includes('Raw materials'),width+' section interaction');
  await page.screenshot({path:`${out}/historical-cost-records-${width}.png`,fullPage:true});
  ok(errors.length===0,width+' console');
  ok(failed.length===0,width+' assets');
  await context.close();
}

const phase4=['workbook.php','purchases.php','shipments.php','landed-costs.php','product-matching.php','profitability.php','cogs-publishing.php','settings.php'];
for(const [width,height] of sizes)for(const route of phase4){
  const context=await browser.newContext({viewport:{width,height}});
  const page=await context.newPage();
  const errors=[];
  page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto(`http://127.0.0.1:8811/apps/cost-manager/${route}?year=2026&month=08`);
  await page.waitForLoadState('networkidle');
  ok(await page.locator('#costWorkbook').isVisible(),`${width} ${route} root`);
  ok(await page.locator('.cw-section-link[aria-current="page"]').count()===1,`${width} ${route} active navigation`);
  const metrics=await page.evaluate(()=>({doc:document.documentElement.scrollWidth,client:document.documentElement.clientWidth,h1:document.querySelectorAll('h1').length,ids:[...document.querySelectorAll('[id]')].map(element=>element.id)}));
  ok(metrics.doc<=metrics.client+1,`${width} ${route} no page overflow`);
  ok(metrics.h1===1,`${width} ${route} one h1`);
  ok(new Set(metrics.ids).size===metrics.ids.length,`${width} ${route} unique ids`);
  ok(errors.length===0,`${width} ${route} console`);
  await page.screenshot({path:`${out}/phase4-${route.replace('.php','')}-${width}.png`,fullPage:true});
  await context.close();
}

const context=await browser.newContext({viewport:{width:1440,height:1000}});
const page=await context.newPage();
await page.goto('http://127.0.0.1:8811/apps/cost-manager/workbook.php');
ok(await page.getByRole('link',{name:'Historical Records'}).isVisible(),'shared history link');
await page.screenshot({path:`${out}/cost-workbook-historical-link-1440.png`,fullPage:false});
await context.close();
await browser.close();
console.log(`Historical archive and Phase 4A browser assertions passed: ${n}`);
