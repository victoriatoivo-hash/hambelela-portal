const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CI?{}:{channel:'msedge'})});
 const page=await browser.newPage();let mode='ok',requests=[];
 await page.route('**/*',async route=>{
  const url=new URL(route.request().url());
  if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:'<main id="inputVatPage"><input data-month value="2026-10"><button data-input-vat-export>Export Excel</button><span data-input-vat-export-message></span></main>'});
  requests.push(url.searchParams.get('month'));await new Promise(r=>setTimeout(r,100));
  if(mode==='fail')return route.fulfill({status:500,json:{error:'Export failed fixture'}});
  if(mode==='login')return route.fulfill({contentType:'text/html',body:'Sign in'});
  return route.fulfill({contentType:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',body:Buffer.from('fixture')});
 });
 await page.goto('http://vat-export.test/');await page.addScriptTag({content:fs.readFileSync('assets/js/input-vat-export.js','utf8')});
 const button=page.locator('button');
 let downloaded=page.waitForEvent('download');await button.click();assert(await button.isDisabled());
 assert.equal((await downloaded).suggestedFilename(),'Hambelela-Input-VAT-2026-10.xlsx');
 await page.waitForFunction(()=>!document.querySelector('button').disabled);
 await page.locator('input').fill('2026-11');downloaded=page.waitForEvent('download');await button.click();
 assert.equal((await downloaded).suggestedFilename(),'Hambelela-Input-VAT-2026-11.xlsx');
 await page.waitForFunction(()=>!document.querySelector('button').disabled);
 assert.deepEqual(requests,['2026-10','2026-11']);
 mode='fail';await button.click();await page.waitForFunction(()=>document.querySelector('span').textContent.includes('Export failed fixture'));assert(!await button.isDisabled());
 mode='login';await button.click();await page.waitForFunction(()=>document.querySelector('span').textContent.includes('signed in'));
 await page.locator('input').fill('2026-13');await button.click();assert.match(await page.locator('span').textContent(),/select a month/);assert.equal(requests.length,4);
 await browser.close();console.log('Selected-month download, filename, busy guard, errors and expired session PASS');
})().catch(e=>{console.error(e);process.exit(1)});
