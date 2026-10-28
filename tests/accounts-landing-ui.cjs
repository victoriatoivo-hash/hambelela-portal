const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const template=read('apps/accounts/index.php'),css=read('assets/css/accounts-dashboard.css'),js=read('assets/js/accounts-dashboard.js');
function render(owner,payment){
 const source=template.replace('declare(strict_types=1);','').replace(/^(?:require_once|include) .*;.*$/mg,'').replace(/<\?php include BASE_PATH\.'\/shared\/footer.php'; \?>/g,'');
 const prelude=`<?php const BASE_PATH=${JSON.stringify(root.replaceAll('\\','/'))}; const BASE_URL=''; const APP_NAME='Portal'; function accounts_require_workspace_access(){} function accounts_is_accountant(){return ${!owner?'true':'false'};} function accounts_is_owner(){return ${owner?'true':'false'};} function amendments_unread_count(){return 2;} function portal_user_can_access_feature($f){return ${payment?'true':'false'};} ?>`;
 return execFileSync(process.env.PHP_BINARY||'php',[],{input:prelude+source,encoding:'utf8'});
}
(async()=>{
 assert(!/accounts\.css|accounts-hierarchy\.css/.test(template));assert(!/#721b1a|#ab3619|#f07420|\b(Figtree|Inter|Roboto|Arial)\b/i.test(css));
 const routes=['input-vat.php','output-vat.php','import-vat.php','paye.php','vat-reconciliation.php','amendments.php','sage-reconciliation.php','asset-register.php','payment-reconciliation.php'];
 const owner=render(true,true),accountant=render(false,false);
 for(const route of routes)assert(owner.includes(`href="${route}"`),route);
 for(const route of routes.slice(6))assert(!accountant.includes(`href="${route}"`),'permission '+route);
 const browser=await chromium.launch({headless:true,...(process.env.CI?{}:{channel:'msedge'})}),page=await browser.newPage();
 await page.route('**/*',r=>r.abort());fs.mkdirSync(path.join(root,'verification/accounts-landing'),{recursive:true});
 for(const width of [1680,1024,767,390,320]){
  await page.setViewportSize({width,height:1000});await page.setContent(`<html><head><style>body{margin:0}${css.replace(/^@import.*$/mg,'')}</style></head><body>${owner.replace(/<script[\s\S]*?<\/script>/g,'')}</body></html>`);await page.addScriptTag({content:js});
  const m=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth,font:getComputedStyle(document.querySelector('main')).fontFamily,title:getComputedStyle(document.querySelector('h1')).fontSize,columns:getComputedStyle(document.querySelector('[data-accounts-grid]')).gridTemplateColumns.split(' ').length,heights:[...document.querySelectorAll('[data-accounts-app]')].map(x=>x.getBoundingClientRect().height)}));
  assert(!m.overflow,`${width} card overflow`);assert.match(m.font,/Jost/);assert.equal(m.title,'28px');assert.equal(m.columns,width<768?1:2);if(width>=768)for(let i=0;i<8;i+=2)assert.equal(m.heights[i],m.heights[i+1]);
  await page.getByRole('button',{name:'List view',exact:true}).click();assert.equal(await page.locator('.is-list').count(),1);assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width} list overflow`);
  await page.getByRole('button',{name:'Card view',exact:true}).click();await page.getByRole('searchbox').fill('paye');assert.equal(await page.locator('[data-accounts-app]:visible').count(),1);
  await page.getByRole('searchbox').fill('not-a-real-app');assert(await page.locator('[data-accounts-empty]').isVisible());await page.getByRole('searchbox').fill('');assert.equal(await page.locator('[data-accounts-app]:visible').count(),9);
  await page.screenshot({path:path.join(root,`verification/accounts-landing/cards-${width}.png`),fullPage:true});console.log(`${width}px layout/search/toggles PASS`);
 }
 await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await page.locator('[data-accounts-app]').first().evaluate(x=>getComputedStyle(x).transitionDuration),'0s');await browser.close();console.log('Role boundaries, routes, Jost sizes and reduced motion PASS');
})().catch(e=>{console.error(e);process.exit(1)});
