const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/marketing-workspace.js'),'utf8');
const helpers=source.slice(0,source.indexOf('// The top-level visual viewport'));
const guard=source.slice(source.indexOf('// All Marketing edit/detail'),source.indexOf('// One form, one final submission'));
const calendar=fs.readFileSync(path.join(__dirname,'../assets/js/portal-date-picker.js'),'utf8');
const form=`<form method="post"><input name="csrf" value="synthetic" type="hidden"><textarea name="body"></textarea><input type="datetime-local" name="channel_schedule[facebook]"><input type="checkbox" name="prepared" value="1"><select name="mode"><option>publish</option><option>prepare</option></select><input type="file" name="asset"><button>Save</button></form>`;
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.platform==='win32'?{executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'}:{})});
 try{
 for(const width of [1280,390]){
  const page=await browser.newPage({viewport:{width,height:850}});
  await page.route('http://marketing.test/**',route=>route.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><main class="mk4-assignment-page" data-mk-unsaved="0">${form}</main>`}));
  await page.goto('http://marketing.test/');
  await page.addScriptTag({content:helpers+guard});
  const dirty=()=>page.evaluate(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented;});
  assert.equal(await dirty(),false,'pristine assignment');
  // Reproduce production ordering: baseline first, shared calendar enhancement later.
  await page.addScriptTag({content:calendar});
  await page.evaluate(()=>window.initialisePortalDatePickers());
  assert.equal(await page.locator('[name="channel_schedule[facebook]_client_timezone"]').count(),1);
  assert.equal(await dirty(),false,'calendar enhancement must not look like unsaved work');
  await page.locator('textarea').fill('Unsent message');assert.equal(await dirty(),true,'real message protected');
  await page.locator('textarea').fill('');assert.equal(await dirty(),false,'reverted message clean');
  await page.evaluate(()=>{document.querySelector('[name="channel_schedule[facebook]"]').value='2026-11-24T17:00';});
  assert.equal(await dirty(),true,'real date change protected, including picker hidden submit field');
  await page.evaluate(()=>{document.querySelector('[name="channel_schedule[facebook]"]').value='';});
  await page.locator('[name=prepared]').check();assert.equal(await dirty(),true);await page.locator('[name=prepared]').uncheck();
  await page.locator('select').selectOption('prepare');assert.equal(await dirty(),true);await page.locator('select').selectOption('publish');
  await page.locator('[type=file]').setInputFiles({name:'draft.txt',mimeType:'text/plain',buffer:Buffer.from('synthetic')});assert.equal(await dirty(),true,'unsent file protected');await page.locator('[type=file]').setInputFiles([]);
  assert.equal(await dirty(),false,'all changes reverted');
  await page.locator('textarea').fill('Draft');
  await page.evaluate(()=>{document.querySelector('form').addEventListener('submit',e=>e.preventDefault(),{once:true});document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));});
  assert.equal(await dirty(),true,'prevented submission retains draft protection');
  await page.evaluate(()=>document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
  assert.equal(await dirty(),false,'valid submitted form may navigate');
  await page.reload();await page.addScriptTag({content:helpers+guard});await page.addScriptTag({content:calendar});await page.evaluate(()=>window.initialisePortalDatePickers());
  assert.equal(await dirty(),false,'saved/reloaded blank reply remains clean');
  await page.evaluate(()=>document.querySelector('main').dataset.mkUnsaved='1');assert.equal(await dirty(),true,'server rejected save retains protection');
  await page.close();console.log('PASS Marketing clean/calendar/message/date/file/save guards at '+width+'px');
 }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
