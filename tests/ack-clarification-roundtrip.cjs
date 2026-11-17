// Two independent browser sessions, production popup code and production Service against isolated MariaDB.
const {chromium}=require('playwright'), assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const phpDir=process.env.ACK_TEST_PHP_DIR,php=phpDir?path.join(phpDir,'php.exe'):'php',options=phpDir?['-n','-d','extension_dir='+path.join(phpDir,'ext'),'-d','extension=pdo_mysql']:[];
const service=(actor,action,data={})=>JSON.parse(execFileSync(php,[...options,path.join(__dirname,'fixtures/clarification-service-cli.php'),String(actor),action],{input:JSON.stringify(data),encoding:'utf8'}));
const script=fs.readFileSync(path.join(process.env.NOTICE_ASSETS||path.join(__dirname,'../assets'),'js/acknowledgments-popup.js'),'utf8');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_BIN});
 try{
  const id=Number(service(71,'seed').id);
  async function login(actor,width){
   const page=await browser.newPage({viewport:{width,height:820}});page.setDefaultTimeout(16000);
   await page.route('https://notice.test/**',async route=>{
    const u=new URL(route.request().url());
    if(u.pathname==='/')return route.fulfill({contentType:'text/html; charset=utf-8',body:`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script>window.HambelelaPortalUser={id:${actor+(process.env.NOTICE_ASSETS?1000:0)}}</script><script defer src="/popup.js"></script><section id="ack-response-chats" hidden><div data-chat-items></div></section>`});
    if(u.pathname==='/popup.js')return route.fulfill({contentType:'text/javascript; charset=utf-8',body:script});
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
  const recipient=72+(process.env.NOTICE_ASSETS?1000:0);
  service(71,'chat_start',{employee:recipient,message:'Which shelf is ready?',request_key:require('crypto').randomUUID()});
  await form(phone).getByText('Which shelf is ready?',{exact:true}).waitFor();
  assert.equal(await form(phone).getByRole('checkbox').count(),0);
  await form(phone).getByRole('button',{name:'Respond Later'}).click();
  await phone.locator('#ack-response-chats button').filter({hasText:'Respond Later'}).click();
  await form(phone).locator('textarea').fill('The top shelf.');
  await form(phone).getByRole('button',{name:'Send Reply'}).click();
  await form(admin).getByRole('button',{name:'Open conversation'}).click();
  await form(admin).getByText('The top shelf.',{exact:true}).waitFor();
  await form(admin).locator('textarea').fill('Is it labelled?');
  await form(admin).getByRole('button',{name:'Send Reply'}).click();
  await form(phone).getByText('Is it labelled?',{exact:true}).waitFor();
  await form(phone).locator('textarea').fill('Yes, it is.');
  await form(phone).getByRole('button',{name:'Send Reply'}).click();
  await form(admin).getByRole('button',{name:'Open conversation'}).click();
  await form(admin).getByText('Yes, it is.',{exact:true}).waitFor();
  await form(admin).getByRole('button',{name:'Mark completed'}).click();
  await form(admin).waitFor({state:'detached'});
  assert.equal(service(71,'chat_list').chats[0].status,'Completed');
  console.log('PASS: mobile employee and desktop owner exchange four messages in one private chat, defer, reopen and resolve explicitly.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
