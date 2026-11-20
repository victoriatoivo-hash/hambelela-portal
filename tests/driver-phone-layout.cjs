// Exercise the real stylesheet and native dialogs with synthetic data only.
const {chromium}=require('playwright-core'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
(async()=>{const server=spawn(process.execPath,['tests/driver-compact-preview.cjs'],{stdio:['ignore','pipe','inherit']});let browser;
try{const url=await new Promise((resolve,reject)=>{server.stdout.once('data',s=>resolve(String(s).trim()));server.once('error',reject);});
browser=await chromium.launch({channel:'chrome',headless:true});
for(const [width,height] of [[375,667],[390,844],[360,800],[412,915]]){
const page=await browser.newPage({viewport:{width,height},isMobile:true,hasTouch:true,reducedMotion:'reduce'});await page.goto(url);const cards=page.locator('.driver-app-card');await cards.first().waitFor();assert.equal(await cards.count(),5);
assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'no horizontal overflow '+width);
const capture=async name=>{if(width===390){require('node:fs').mkdirSync('driver-screen-check',{recursive:true});await page.screenshot({path:'driver-screen-check/'+name+'.png'});}};
assert.ok(await page.getByRole('navigation',{name:'Driver navigation'}).isVisible());await capture('01-deliveries');await cards.nth(1).locator('button').click();assert.ok(await page.locator('.df-metrics').isVisible());await capture('02-details');await page.getByRole('button',{name:'Complete delivery',exact:true}).click();
const sheet=page.locator('dialog[open]');await sheet.getByLabel('Already paid').check();assert.equal(await sheet.locator('.driver-app-check').first().evaluate(el=>getComputedStyle(el).display),'flex');await capture('03-completion');assert.equal(await sheet.locator('[name=amount]').inputValue(),'0.00');await sheet.getByRole('button',{name:'Confirm Completion'}).scrollIntoViewIfNeeded();
const bounds=await sheet.boundingBox();assert.ok(bounds.x>=-1&&bounds.x+bounds.width<=width+1&&bounds.height<=height+1,'sheet fits '+width);await sheet.getByRole('button',{name:'Close',exact:true}).click();await page.close();
console.log('PASS responsive driver list, details and mandatory payment sheet',width,height);
}const owner=await browser.newPage({viewport:{width:1440,height:900},reducedMotion:'reduce'});await owner.goto(url+'?owner=1');await owner.locator('.driver-app-card').first().waitFor();assert.ok(await owner.locator('#delivery-driver').isVisible());assert.equal(await owner.locator('#delivery-driver').inputValue(),'18');await owner.locator('.driver-app-card button').nth(1).click();assert.ok(await owner.locator('dialog[open]').isVisible());assert.equal(await owner.getByRole('button',{name:'Complete delivery',exact:true}).count(),0);await owner.close();console.log('PASS desktop Owner driver preview with visible selector and read-only details');
}finally{if(browser)await browser.close();server.kill();}})().catch(e=>{console.error(e);process.exitCode=1;});
