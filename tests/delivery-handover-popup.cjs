const assert=require('node:assert/strict'),fs=require('node:fs'),http=require('node:http'),path=require('node:path'),os=require('node:os');
const {chromium}=require('playwright'),root=path.resolve(__dirname,'..');
(async()=>{
 const server=http.createServer((req,res)=>{const p=new URL(req.url,'http://localhost').pathname;
  if(p==='/'){res.setHeader('Content-Type','text/html');res.end('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/delivery-handover-popup.css"><h1>Portal test</h1><script>window.HambelelaPortalUser={id:1};</script><script defer data-endpoint="/apps/delivery/front-api.php" src="/assets/js/delivery-handover-popup.js"></script>');return;}
  const file=path.join(root,p);if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.writeHead(404).end();return;}res.setHeader('Content-Type',p.endsWith('.js')?'text/javascript':'text/css');res.end(fs.readFileSync(file));
 });await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try{for(const width of [1280,390,320]){
  const context=await browser.newContext({viewport:{width,height:850}}),page=await context.newPage(),errors=[],posts=[];
  let items=[{id:1,reference:'TEST-DELIVERY',customer:'Synthetic customer <img src=x>',driver:'Synthetic Driver',driver_id:2,acceptance_event_id:77,accepted_at:'2026-11-21 09:00:00'}],fail=false;
  page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/apps/delivery/front-api.php*',async route=>{
   const body=route.request().postDataJSON();if(!body)return route.fulfill({json:{employee_id:1,csrf:'test-token',items}});
   posts.push(body);if(fail)return route.fulfill({status:503,json:{error:'Synthetic failure'}});
   items=[];return route.fulfill({json:{ok:true,result:{id:1}}});
  });
  const url='http://127.0.0.1:'+server.address().port;await page.goto(url);await page.locator('dialog[open]').waitFor();
  assert.equal(await page.locator('dialog img').count(),0,'Untrusted customer escaped');assert.equal(await page.locator('input[type=number]').count(),0);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await page.screenshot({path:path.join(os.tmpdir(),'handover-popup-'+width+'.png')});
  await page.getByRole('button',{name:'Remind me in 15 minutes'}).click();assert.equal(posts.length,0);await page.reload();await page.waitForTimeout(200);assert.equal(await page.locator('dialog[open]').count(),0,'Snooze survives refresh');
  await page.evaluate(()=>{localStorage.clear();window.dispatchEvent(new Event('focus'));});await page.locator('dialog[open]').waitFor();
  await page.keyboard.press('Escape');assert.equal(posts.length,0,'Escape never confirms');
  await page.evaluate(()=>{localStorage.clear();window.dispatchEvent(new Event('focus'));});await page.locator('dialog[open]').waitFor();
  fail=true;await page.getByRole('button',{name:'Confirm',exact:true}).click();await page.getByRole('alert').filter({hasText:'Synthetic failure'}).waitFor();assert.equal(await page.locator('dialog[open]').count(),1);
  fail=false;await page.getByRole('button',{name:'Confirm',exact:true}).click();await page.locator('dialog[open]').waitFor({state:'detached'});
  assert.equal(posts[0].uuid,posts[1].uuid,'Retry preserves idempotency key');assert.equal(posts[1].confirmed,true);assert.equal(posts[1].acceptance_event_id,77);assert.equal(posts[1].parcel_count,undefined);
  await page.reload();await page.waitForTimeout(200);assert.equal(await page.locator('dialog[open]').count(),0,'Confirmed stays closed');
  items=[{id:1,reference:'TEST-NEW',customer:'Synthetic',driver:'Driver',driver_id:2,acceptance_event_id:78,accepted_at:'2026-11-21 10:00:00'}];await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.locator('dialog[open]').waitFor();
  items=[];await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.locator('dialog[open]').waitFor({state:'detached'});assert.equal(posts.length,2,'Remote completion closes without another command');
  if(width===1280){
   items=[{id:1,reference:'TEST-TABS',customer:'Synthetic',driver:'Driver',driver_id:2,acceptance_event_id:79,accepted_at:'2026-11-21 10:00:00'}];
   await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.locator('dialog[open]').waitFor();
   const second=await context.newPage();await second.goto(url);await second.waitForTimeout(200);
   assert.equal((await page.locator('dialog[open]').count())+(await second.locator('dialog[open]').count()),1,'Only one reminder across tabs');
   if(await page.locator('dialog[open]').count())await page.getByRole('button',{name:'Remind me in 15 minutes'}).click();else await second.getByRole('button',{name:'Remind me in 15 minutes'}).click();
   await second.evaluate(()=>window.dispatchEvent(new Event('focus')));await second.waitForTimeout(200);assert.equal(await second.locator('dialog[open]').count(),0,'Snooze shared across tabs');
  }
  assert.deepEqual(errors,[]);await context.close();console.log('PASS reminder desktop/mobile '+width+': pending, snooze, escape, failure/retry, confirmation, remote resolution, no overflow');
 }}finally{await browser.close();server.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
