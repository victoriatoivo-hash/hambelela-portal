const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/User/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const base=path.resolve(__dirname,'..'),out=path.join(base,'task-popup-qa');fs.mkdirSync(out,{recursive:true});
const script=fs.readFileSync(path.join(base,'assets/js/task-assignment-popup.js'),'utf8');
const css=fs.readFileSync(path.join(base,'assets/css/task-assignment-popup.css'),'utf8').replace(/^@import[^\n]+/,'');
const sample=(id,extra={})=>({id,related_id:id,related_type:'checklist_task',title:'New task assigned',task_name:'Prepare courier waybills',instructions:'<p>Check the courier details and print the approved waybills for collection.</p>',priority:'normal',status:'new',task_mode:'manual',assigned_by:'Victoria',due_at:'2026-10-07 14:00:00',delivered_at:null,...extra});
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  for(const viewport of [{width:1366,height:768},{width:390,height:844},{width:430,height:932}]){
   const page=await browser.newPage({viewport});let records=[sample(1),sample(2),sample(3),sample(4)],claims=0,reads=0;
   await page.route('http://task-popup.test/**',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.pathname==='/api/notifications.php'){
     const params=new URLSearchParams(request.postData()||'');let payload;
     if(params.get('action')==='notification_claim'){
      const n=records.find(n=>n.id===Number(params.get('notification_id')));const claimed=!!n&&!n.delivered_at;
      if(claimed){n.delivered_at='now';claims++;}payload={ok:true,claimed};
     }else if(params.get('action')==='notification_viewed'){reads++;payload={ok:true};}
     else payload={ok:true,task_popups:records.filter(n=>!n.delivered_at||n.id===Number(url.searchParams.get('active_task_notification')))};
     await route.fulfill({json:payload});return;
    }
    await route.fulfill({contentType:'text/html',body:'<html><body style="background:#F8F7F2;margin:0"><header style="padding:20px;background:white;color:#465636">Hambelela · Orders</header><main style="padding:30px;color:#465636;font-family:sans-serif"><h1>Orders</h1><label>Order note <input id="note" value="Continue working"></label><p>My Tasks remain available after dismissing notifications.</p></main></body></html>'});
   });
   await page.goto('http://task-popup.test/orders');
   // Use the actual bundled Jost font for deterministic visual QA without network access.
   const font=fs.readFileSync('C:/Users/User/Documents/New project/assets/fonts/jost-variable.woff2').toString('base64');
   await page.addStyleTag({content:`@font-face{font-family:Jost;src:url(data:font/woff2;base64,${font}) format('woff2');font-weight:100 900}`+css});
   await page.addScriptTag({content:script});await page.locator('#note').focus();
   await page.evaluate(records=>window.HambelelaTaskPopups.accept(records),records);
   await page.waitForSelector('.task-assignment-popup');
   assert.equal(await page.locator('.task-assignment-popup').count(),1,'one popup only');
   assert.equal(await page.locator('.task-popup-queue').textContent(),'1 of 4');
   assert(await page.locator('#note').evaluate(n=>n===document.activeElement),'popup does not steal typing focus');
   await page.waitForTimeout(250);
   const metrics=await page.locator('.task-assignment-popup').evaluate(n=>({r:n.getBoundingClientRect().toJSON(),font:getComputedStyle(n).fontFamily,button:getComputedStyle(n.querySelector('.task-popup-start')).backgroundColor,scroll:document.documentElement.scrollWidth}));
   assert(metrics.r.x>=0&&metrics.r.right<=viewport.width,'no horizontal popup overflow');assert(metrics.scroll<=viewport.width,'no page overflow');
   assert(metrics.font.includes('Jost'));assert.equal(metrics.button,'rgb(150, 99, 19)');
   if(viewport.width<768){assert(metrics.r.bottom<=viewport.height);assert((await page.locator('.task-popup-start').boundingBox()).height>=44);}
   await page.screenshot({path:path.join(out,`normal-queue-${viewport.width}.png`)});
   await page.locator('.task-popup-close').click();await page.waitForFunction(()=>document.querySelector('.task-assignment-popup')?.dataset.notificationId==='2');
   assert.equal(reads,0,'dismiss does not mark read');assert.equal(claims,2,'next task claimed exactly once');
   // The next poll does not replay a previously acknowledged ID.
   await page.evaluate(records=>window.HambelelaTaskPopups.accept(records),records);
   assert.equal(await page.locator('.task-assignment-popup').getAttribute('data-notification-id'),'2');
   await page.evaluate(()=>window.HambelelaTaskPopups.accept([]));assert.equal(await page.locator('.task-assignment-popup').count(),0,'stale active popup removed');
   records=[sample(8,{priority:'urgent'})];await page.evaluate(records=>window.HambelelaTaskPopups.accept(records),records);await page.waitForSelector('.is-urgent');await page.waitForTimeout(250);
   await page.screenshot({path:path.join(out,`urgent-${viewport.width}.png`)});
   await page.evaluate(()=>window.HambelelaTaskPopups.accept([]));
   records=[sample(9,{task_mode:'recurring',task_name:'Daily opening stock check'})];await page.evaluate(records=>window.HambelelaTaskPopups.accept(records),records);await page.waitForSelector('.task-assignment-popup');await page.waitForTimeout(250);
   await page.screenshot({path:path.join(out,`recurring-${viewport.width}.png`)});
   // Exercise direct actions through the actual presenter without mutating business state.
   await page.evaluate(()=>{window.openTaskPanel=(id,mode)=>{window.taskAction={id,mode};return true;};});
   await page.locator('.task-popup-review').click();await page.waitForFunction(()=>window.taskAction?.mode==='review');assert.equal(reads,1);
   records=[sample(10)];await page.evaluate(records=>window.HambelelaTaskPopups.accept(records),records);await page.waitForSelector('.task-assignment-popup');await page.locator('.task-popup-start').click();await page.waitForFunction(()=>window.taskAction?.mode==='start');assert.equal(reads,2);
   assert.equal(await page.locator('.task-assignment-popup').count(),0);
   await page.screenshot({path:path.join(out,`dismissed-${viewport.width}.png`)});
   console.log(`PASS queue, focus, persistence, stale suppression, Review/Start dispatch, Jost, accent and layout at ${viewport.width}x${viewport.height}`);
   await page.close();
  }
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
