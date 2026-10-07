const {createRequire}=require('node:module');
const fs=require('node:fs');
const path=require('node:path');
const runtimeRequire=createRequire('C:/Users/User/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/package.json');
const {chromium}=runtimeRequire('playwright');
const base='http://127.0.0.1:8099';
let passes=0;
function check(ok,label){if(!ok)throw new Error(label);console.log('PASS '+label);passes++;}
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 try{
  const anonymous=await browser.newContext();
  const denied=await anonymous.request.get(base+'/apps/hr-portal/download-certificate.php?file=cert_1_example.pdf',{maxRedirects:0});
  check(denied.status()===302,'unauthenticated document request redirects to login');
  async function account(id){const c=await browser.newContext();await c.request.get(base+'/login-test.php?id='+id);return c;}
  const admin=await account(1),employee=await account(10),other=await account(11);
  async function request(c,route,file,status){const r=await c.request.get(base+'/apps/hr-portal/'+route+'?file='+file);check(r.status()===status,route+' '+file+' returns '+status);return r;}
  const pdf=await request(admin,'download-certificate.php','cert_1_example.pdf',200);
  check(pdf.headers()['content-type']==='application/pdf','PDF response uses PDF MIME');
  check(pdf.headers()['content-disposition'].startsWith('inline;'),'document opens inline');
  check(pdf.headers()['cache-control'].includes('no-store')&&pdf.headers()['x-content-type-options']==='nosniff','private document response blocks caching and MIME sniffing');
  check((await pdf.body()).subarray(0,5).toString()==='%PDF-','PDF bytes are streamed');
  const png=await request(admin,'download-certificate.php','cert_2_example.png',200);
  check(png.headers()['content-type']==='image/png','image response uses PNG MIME');
  await request(employee,'view-my-certificate.php','cert_1_example.pdf',200);
  await request(other,'view-my-certificate.php','cert_1_example.pdf',403);
  await request(employee,'view-my-certificate.php','cert_1_forged.pdf',403);
  await request(employee,'download-certificate.php','cert_1_example.pdf',403);
  await request(admin,'download-certificate.php','unreferenced.pdf',403);
  await request(admin,'download-certificate.php','missing.pdf',404);
  await request(admin,'download-certificate.php','cert_1_spoof.pdf',404);
  await request(admin,'download-certificate.php','..%2Fconfig.php',400);
  await request(admin,'download-certificate.php','anything.svg',400);
  const array=await admin.request.get(base+'/apps/hr-portal/download-certificate.php?file[]=cert_1_example.pdf');
  check(array.status()===400,'array query parameter is rejected');
  const page=await admin.newPage();
  for(const width of [1366,390,430]){
   await page.setViewportSize({width,height:900});
   await page.goto(base+'/apps/hr-portal/history-preview.php');
   await page.locator('[data-leave-history] table').waitFor();
   const layout=await page.evaluate(()=>({page:document.documentElement.scrollWidth,viewport:innerWidth,headers:Array.from(document.querySelectorAll('[data-leave-history] th')).map(x=>x.textContent.trim()),wrappers:document.querySelectorAll('.hr-table-viewport').length}));
   check(layout.page<=layout.viewport,'no page-wide overflow at '+width+'px');
   check(layout.headers[5]==='Supporting Document','document column follows Status at '+width+'px');
   check(await page.getByText('Not uploaded',{exact:true}).count()===1,'empty references are explicit at '+width+'px');
   check(await page.getByText('File unavailable',{exact:true}).count()===2,'missing and invalid files have no broken links at '+width+'px');
   if(width<600){
    await page.getByRole('link',{name:'View document'}).first().scrollIntoViewIfNeeded();
    const reachable=await page.getByRole('link',{name:'View document'}).first().evaluate(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth;});
    check(reachable,'supporting document is reachable within the mobile table at '+width+'px');
   }
   await page.screenshot({path:path.join('verification','leave-history-'+width+'.png'),fullPage:true});
  }
  await page.setViewportSize({width:1366,height:900});
  await page.goto(base+'/apps/hr-portal/history-preview.php');
  const link=page.getByRole('link',{name:'View document'}).nth(1);
  const popupPromise=page.waitForEvent('popup');await link.click();const popup=await popupPromise;
  await popup.waitForLoadState();
  check(popup.url().includes('download-certificate.php?file=cert_2_example.png'),'document link opens the authenticated viewer in a new tab');
  check(await popup.evaluate(()=>window.opener===null),'new document tab has no opener');
  console.log('TOTAL '+passes+' browser/HTTP checks passed');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
