// Render the production launcher and styles against synthetic page content.
const assert=require('node:assert/strict'),fs=require('node:fs'),puppeteer=require('puppeteer-core');
const read=p=>fs.readFileSync(p,'utf8');
const header=read('shared/header.php'),start=header.indexOf('<div class="ack-contact-control">'),end=header.indexOf('<div class="portal-notification-control">',start);
assert.ok(start>header.indexOf('data-portal-online-widget')&&end>start);
const buttons=header.slice(start,end).replaceAll('<?=BASE_URL?>','');
(async()=>{
const browser=await puppeteer.launch({executablePath:process.env.CHROME_BIN||'/usr/bin/google-chrome',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
try{
 for(const width of [1280,375,320]){
  const page=await browser.newPage();await page.setViewport({width,height:720});
  await page.setContent('<meta name="viewport" content="width=device-width,initial-scale=1"><style>'+read('assets/css/portal.css')+read('assets/css/portal-responsive.css')+read('assets/css/ack-communication.css')+'</style><main><div style="display:flex;justify-content:flex-end"><div class="portal-header-actions"><button>Online</button>'+buttons+'<div class="portal-notification-control"><a href="#bell" data-notification-button class="portal-header-action portal-header-notifications portal-notification-button">Bell</a></div></div></div><p id="outside">Other content</p></main>');
  await page.$eval('#outside',el=>el.style.marginTop='350px');
  await page.addScriptTag({content:read('assets/js/ack-communication.js')});
  const trigger='.ack-contact-launch',popup='#ack-contact-options';
  const bounds=await page.evaluate(()=>[document.querySelector('.ack-contact-launch'),document.querySelector('[data-notification-button]')].map(el=>{const r=el.getBoundingClientRect();return [r.left,r.right,r.height,r.top+r.height/2]}));
  assert.ok(bounds[0][2]>0&&bounds[0][1]<=bounds[1][0]+1&&Math.abs(bounds[0][3]-bounds[1][3])<2,'Same row before bell at '+width);
  await page.click(trigger);
  assert.deepEqual(await page.$$eval(popup+' a',els=>els.map(el=>el.hash)),['#private','#anonymous']);
  assert.ok(await page.$eval(popup,el=>{const r=el.getBoundingClientRect();return el.matches(':popover-open')&&r.left>=0&&r.right<=innerWidth&&r.bottom<=innerHeight&&el.contains(document.activeElement);}));
  await page.keyboard.press('Escape');assert.ok(await page.$eval(trigger,el=>document.activeElement===el&&el.getAttribute('aria-expanded')==='false'));
  await page.click(trigger);await page.click('#outside');assert.ok(await page.$eval(popup,el=>el.hidden));
  await page.focus(trigger);await page.keyboard.press('Enter');await page.keyboard.press('Tab');await page.keyboard.press('Tab');
  assert.ok(await page.$eval('[data-notification-button]',el=>el===document.activeElement));
  console.log('PASS: inline header, popup bounds, destinations, focus, Escape, outside click and keyboard at '+width+'px');await page.close();
 }
}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
