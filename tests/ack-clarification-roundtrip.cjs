// Two independent browser sessions, production popup code and production Service against isolated MariaDB.
const {chromium}=require('playwright'), assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const service=(actor,action,data={})=>JSON.parse(execFileSync('php',[path.join(__dirname,'fixtures/clarification-service-cli.php'),String(actor),action],{input:JSON.stringify(data),encoding:'utf8'}));
const script=fs.readFileSync(path.join(process.env.NOTICE_ASSETS||path.join(__dirname,'../assets'),'js/acknowledgments-popup.js'),'utf8');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_BIN});
 try{
  const id=Number(service(71,'seed').id);
  async function login(actor,width){
   const page=await browser.newPage({viewport:{width,height:820}});page.setDefaultTimeout(16000);
   await page.route('https://notice.test/**',async route=>{
    const u=new URL(route.request().url());
    if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><script defer src="/popup.js"></script>'});
    if(u.pathname==='/popup.js')return route.fulfill({contentType:'text/javascript',body:script});
    const result=service(actor,u.searchParams.get('action'),{...Object.fromEntries(u.searchParams),...(route.request().postDataJSON()||{})});
    return route.fulfill({status:result.error?403:200,json:result});
   });
   await page.goto('https://notice.test/');return page;
  }
  const employee=await login(72,390),admin=await login(71,1440);
  const form=p=>p.locator('dialog[open]');
  await form(employee).getByRole('button',{name:'I Don’t Understand'}).click();
  await form(employee).locator('textarea').fill('Which blue tray?');
  await form(employee).getByRole('button',{name:'Send clarification'}).click();
  await form(admin).getByText('Which blue tray?',{exact:false}).waitFor();
  assert((await form(admin).innerText()).includes('Fixture Employee'));
  assert((await form(admin).innerText()).includes('Check the blue tray.'));
  const turn=service(71,'clarification_poll').turn;
  assert.equal(service(3,'clarification_poll').turn,null);
  await form(admin).getByRole('button',{name:'Respond Later'}).click();await form(admin).waitFor({state:'detached'});
  assert.equal(service(71,'clarification_poll').turn,null);
  assert.equal(service(71,'detail',{id}).status,'clarification');
  await employee.close(); // Employee offline when management replies from the existing Acknowledgments section.
  const reply={recipient_id:id,expected_event:Number(turn.event_id),action:'reply',message:'Use the dispatch tray.',request_key:require('crypto').randomUUID()};
  assert(service(71,'respond',reply).ok);assert(service(71,'respond',reply).ok);
  const phone=await login(72,390);
  await form(phone).getByText('Use the dispatch tray.',{exact:false}).waitFor();
  assert((await form(phone).innerText()).includes('Which blue tray?'));
  await form(phone).getByRole('button',{name:'I Still Don’t Understand'}).click();
  await form(phone).locator('textarea').fill('Which shelf?');
  await form(phone).getByRole('button',{name:'Send clarification'}).click();
  await form(admin).getByText('Which shelf?',{exact:false}).waitFor();
  await form(admin).locator('textarea').fill('Top shelf.');
  await form(admin).getByRole('button',{name:'Send reply'}).click();
  await form(phone).getByText('Top shelf.',{exact:false}).waitFor();
  assert(service(71,'respond',{...reply,request_key:require('crypto').randomUUID()}).error,'Old form cannot reply to newer turn');
  await phone.reload();await form(phone).getByText('Top shelf.',{exact:false}).waitFor();
  assert.equal(service(72,'detail',{id}).status,'pending');
  await form(phone).getByRole('button',{name:'I Understand / Acknowledge'}).click();await form(phone).waitFor({state:'detached'});
  const record=service(72,'detail',{id});
  assert.equal(record.status,'acknowledged');
  assert.deepEqual(record.events.filter(e=>['clarify','reply','acknowledge'].includes(e.event_type)).map(e=>e.event_type),['clarify','reply','clarify','reply','acknowledge']);
  assert.equal(service(72,'clarification_poll').turn,null);assert.equal(service(71,'clarification_poll').turn,null);
  console.log('PASS: automatic bidirectional prompts, deferred/offline delivery, repeated turns, stale and duplicate responses, explicit acknowledgment and original history.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
