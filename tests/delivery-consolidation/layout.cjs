const {chromium}=require('playwright'),{spawn}=require('child_process'),fs=require('fs'),path=require('path'),assert=require('assert');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:51843';
const php=process.env.PHP_BIN||'C:/Users/User/Documents/New project/_driver_php74_check/runtime/php.exe';
const extensions=process.platform==='win32'?['-d','extension_dir='+path.join(path.dirname(php),'ext'),'-d','extension=mbstring']:[];
const server=spawn(php,[...extensions,'-S','127.0.0.1:51843','-t',root,'tests/delivery-consolidation/preview.php'],{cwd:root,windowsHide:true,stdio:'pipe'});
let errors='';server.stderr.on('data',b=>{errors+=b;});
const results=[];let browser;
const request=url=>new Promise((resolve,reject)=>require('http').get(url,r=>{r.resume();r.on('end',()=>resolve({ok:r.statusCode===200,status:r.statusCode,headers:r.headers}));}).on('error',reject));
(async()=>{
 for(let i=0;i<60;i++){try{if((await request(origin+'/apps/delivery/driver-operations.php')).ok)break;}catch{}await new Promise(r=>setTimeout(r,200));}
 browser=await chromium.launch({headless:true,channel:'chrome'});
 const context=await browser.newContext();
 await context.route('**/*',route=>new URL(route.request().url()).hostname==='127.0.0.1'?route.continue():route.abort());
 const page=await context.newPage();const consoleErrors=[];page.on('pageerror',e=>consoleErrors.push(e.stack));
 const out=path.join(__dirname,'screens');fs.mkdirSync(out,{recursive:true});
 for(const [name,url,selector] of [['dashboard','driver-operations.php','#owner-operations'],['proof','delivery-details.php?delivery_id=16','[data-owner-proof]'],['viewer','view-as-driver.php?driver=3','#owner-driver-view']]){
  for(const width of [1448,1280,1024,768,430,390,360]){
   await page.setViewportSize({width,height:1000});await page.goto(origin+'/apps/delivery/'+url);await page.locator(selector).waitFor();
   await page.evaluate(()=>document.fonts.ready);
   if(name==='dashboard')await page.locator('#operations-rows').getByText('Synthetic Customer').waitFor();
   if(name==='viewer'){await page.locator('#view-workspace:not([hidden])').waitFor();await page.frameLocator('#view-device iframe').getByText('Synthetic driver preview',{exact:true}).waitFor();}
   const metrics=await page.locator(selector).evaluate(el=>{
    const h=el.querySelector('h1'),button=el.querySelector('button:not([disabled])'),select=el.querySelector('select');
    return {font:getComputedStyle(el).fontFamily,size:getComputedStyle(el).fontSize,title:getComputedStyle(h).fontSize,weight:getComputedStyle(h).fontWeight,button:button?getComputedStyle(button).fontFamily:null,select:select?getComputedStyle(select).fontFamily:null,overflow:document.documentElement.scrollWidth>innerWidth+1};
   });
   assert(metrics.font.includes('Jost'),name+' Jost');assert.equal(metrics.size,'13px');assert.equal(metrics.weight,'500');assert.equal(metrics.title,width<=768?'26px':'29px');assert(!metrics.overflow,`${name} overflow ${width}`);
   assert(!metrics.button||metrics.button.includes('Jost'));assert(!metrics.select||metrics.select.includes('Jost'));
   assert(await page.locator('.ess-sidebar a[aria-label="Delivery"].is-active').count()===1,'Delivery is active');
   assert(await page.locator(selector+' .delivery-admin-nav').count()===1,'one Delivery workspace nav');
   const renderText=await page.textContent('body');assert(!renderText.match(/(?:PHP )?(?:Warning|Fatal error):/),'PHP rendering error: '+renderText.slice(-1800));
   await page.screenshot({path:path.join(out,`${name}-${width}.png`),fullPage:true});results.push({page:name,width,...metrics});
  }
 }
 await page.goto(origin+'/apps/delivery/driver-operations.php');await page.locator('#operations-rows [data-menu]').waitFor();
 await page.setViewportSize({width:390,height:900});await page.locator('[data-ess-more]').click();assert(await page.locator('#ess-more-dialog').evaluate(e=>e.open));await page.locator('[data-ess-close]').click();
 await page.locator('[data-menu]').first().click();assert(await page.locator('#operations-menu').evaluate(e=>e.open));
 assert((await page.locator('#operations-menu-content a').first().getAttribute('href')).startsWith('/apps/delivery/delivery-details.php'));
 await page.locator('#close-operations-menu').click();await page.locator('.ho-order-link').first().click();await page.locator('[data-owner-proof]').waitFor();
 await page.getByRole('button',{name:'Report discrepancy',exact:true}).click();assert(await page.locator('[data-review-dialog]').evaluate(e=>e.open));await page.locator('[data-review-dialog] [data-close]').click();
 await page.getByRole('link',{name:'Open driver view',exact:true}).click();await page.locator('#owner-driver-view').waitFor();
 assert(page.url().includes('/apps/delivery/view-as-driver.php?driver=3'));
 await page.getByRole('link',{name:'Exit view',exact:true}).click();assert(page.url().includes('/apps/delivery/driver-operations.php'));
 for(const [old,next] of [['driver-operations-dashboard.php','driver-operations.php'],['driver-delivery-details.php?delivery_id=16','delivery-details.php?delivery_id=16'],['view-as-driver.php?driver=3','view-as-driver.php?driver=3']]){
  const r=await request(origin+'/apps/operations/'+old);assert.equal(r.status,302);assert.equal(r.headers.location,'/apps/delivery/'+next);
 }
 assert.equal(consoleErrors.length,0,consoleErrors[0]);fs.writeFileSync(path.join(__dirname,'results.json'),JSON.stringify({layouts:results,crossPageNavigation:true,legacyRedirects:true,consoleErrors},null,2));
 console.log('PASS 21 responsive layouts, Jost/weight/size, Delivery active states, action dialogs, cross-page links and 3 legacy redirects.');
})().catch(e=>{console.error(e);console.error(errors.slice(-3000));process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();server.kill();});
