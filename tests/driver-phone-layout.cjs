// Exercise the real stylesheet and native dialogs with synthetic data only.
const {chromium}=require('playwright-core'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
(async()=>{const server=spawn(process.execPath,['tests/driver-compact-preview.cjs'],{stdio:['ignore','pipe','inherit']});let browser;
async function assertSheet(page,kind,motion){
 const sheet=page.locator('dialog[open]');await sheet.waitFor();
 const observed=await sheet.evaluate(async el=>{
   const name=getComputedStyle(el).animationName;
   await Promise.all(el.getAnimations().map(a=>a.finished));
   const r=el.getBoundingClientRect();return {name,centre:r.left+r.width/2,screen:innerWidth,bottom:r.bottom,height:innerHeight};
 });
 assert.equal(observed.name,motion?'driverSheetSlideUp':'none',kind+' motion');
 assert.ok(Math.abs(observed.centre-observed.screen/2)<2,kind+' horizontally centred');
 assert.ok(Math.abs(observed.bottom-observed.height)<2,kind+' attached to bottom');
}
try{const url=await new Promise((resolve,reject)=>{server.stdout.once('data',s=>resolve(String(s).trim()));server.once('error',reject);});
browser=await chromium.launch({channel:'chrome',headless:true});
for(const [width,height] of [[375,667],[390,844],[360,800],[412,915]]){
const page=await browser.newPage({viewport:{width,height},isMobile:true,hasTouch:true,reducedMotion:width===390?'no-preference':'reduce'});await page.goto(url);const cards=page.locator('.driver-app-card');await cards.first().waitFor();assert.equal(await cards.count(),3);
assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'no horizontal overflow '+width);
const capture=async name=>{if(width===390){require('node:fs').mkdirSync('driver-screen-check',{recursive:true});await page.screenshot({path:'driver-screen-check/'+name+'.png'});}};
assert.ok(await page.getByRole('navigation',{name:'Driver navigation'}).isVisible());assert.equal(await page.locator('[data-tab=active]').getAttribute('aria-pressed'),'true');assert.match(await page.locator('[data-tab=active]').innerText(),/Active\s+3/);assert.equal(await page.locator('[data-job-id="3"],[data-job-id="5"]').count(),0);
await page.locator('[data-tab=completed]').click();await page.locator('[data-job-id="3"]').waitFor();assert.equal(await cards.count(),1);
await page.locator('[data-tab=active]').click();await page.locator('[data-job-id="1"]').waitFor();await capture('01-deliveries');
const options=page.getByRole('button',{name:'Delivery options',exact:true});await options.click();await assertSheet(page,'Options',width===390);await capture('04-options');
await page.locator('[data-filter-tab=progress]').click();await page.locator('dialog[open]').waitFor({state:'hidden'});await page.waitForFunction(()=>document.querySelectorAll('.driver-app-card').length===1);
await options.click();await page.locator('[data-filter-tab=active]').click();await page.locator('dialog[open]').waitFor({state:'hidden'});await page.waitForFunction(()=>document.querySelectorAll('.driver-app-card').length===3);
await cards.nth(1).locator('button').click();assert.ok(await page.locator('.df-metrics').isVisible());await assertSheet(page,'Delivery details',width===390);await capture('02-details');await page.getByRole('button',{name:'Show delivery timing and history'}).click();assert.ok(await page.getByRole('button',{name:'Back to delivery'}).isVisible());await page.getByRole('button',{name:'Back to delivery'}).click();await page.getByRole('button',{name:'Complete Delivery',exact:true}).click();
const sheet=page.locator('dialog[open]');await sheet.getByLabel('Already paid').check();assert.equal(await sheet.locator('.driver-app-check').first().evaluate(el=>getComputedStyle(el).display),'flex');await assertSheet(page,'Payment confirmation',width===390);await capture('03-completion');assert.equal(await sheet.locator('[name=amount]').inputValue(),'0.00');await sheet.getByRole('button',{name:'Confirm Completion'}).scrollIntoViewIfNeeded();
const bounds=await sheet.boundingBox();assert.ok(bounds.x>=-1&&bounds.x+bounds.width<=width+1&&bounds.height<=height+1,'sheet fits '+width);await sheet.getByRole('button',{name:'Close',exact:true}).click();
await page.evaluate(()=>{const shell=document.createElement('nav');shell.className='ess-mobile-nav';shell.setAttribute('aria-label','Mobile navigation');shell.textContent='Deliveries';document.body.append(shell);});
assert.equal(await page.locator('.df-nav').isVisible(),false,'hide duplicate driver bar when dashboard navigation exists');assert.equal(await page.locator('.ess-mobile-nav').isVisible(),true,'show shared dashboard bar');await page.close();
console.log('PASS responsive driver list, details and mandatory payment sheet',width,height);
}const owner=await browser.newPage({viewport:{width:1440,height:900},reducedMotion:'reduce'});await owner.goto(url+'?owner=1');await owner.locator('.driver-app-card').first().waitFor();assert.ok(await owner.locator('#delivery-driver').isVisible());assert.equal(await owner.locator('#delivery-driver').inputValue(),'18');await owner.getByRole('button',{name:'Delivery options',exact:true}).click();await assertSheet(owner,'Desktop options',false);await owner.getByRole('button',{name:'Close',exact:true}).click();await owner.locator('.driver-app-card button').nth(1).click();await assertSheet(owner,'Desktop details',false);assert.ok(await owner.locator('dialog[open]').isVisible());assert.equal(await owner.getByRole('button',{name:'Complete Delivery',exact:true}).count(),0);await owner.close();console.log('PASS desktop Owner driver preview with visible selector and read-only details');
}finally{if(browser)await browser.close();server.kill();}})().catch(e=>{console.error(e);process.exitCode=1;});
