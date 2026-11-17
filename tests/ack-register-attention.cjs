// Exercise the production register renderer; the fixture never contacts the portal.
const {chromium}=require('playwright'), fs=require('node:fs'),assert=require('node:assert/strict');
const read=p=>fs.readFileSync(p,'utf8'),php=read('apps/acknowledgments/index.php');
const markup=php.slice(php.indexOf('<main'),php.lastIndexOf('</main>')+7).replace(/<\?[\s\S]*?\?>/g,'');
(async()=>{const browser=await chromium.launch({executablePath:process.env.CHROME_BIN});try{
 for(const width of [1440,390]){
  const page=await browser.newPage({viewport:{width,height:850}});
  const item=(id,title,status)=>({id,title,status,employee_name:'Test employee',sender_id:1,release_state:'released',created_at:'2026-11-15 10:00:00'});
  const data={owner:true,employee_id:1,records:[item(1,'New notice','pending'),{...item(2,'Unanswered question','clarification'),unread:1},item(3,'Signed instruction','acknowledged')],
   assistance:[{topic:4,employee_name:'Test employee',title:'Help requested',side:'owner',status:'Respond Later'}],
   chats:[{id:5,title:'Management question',employee_name:'Test employee',status:'Respond Later',awaiting_owner:0},{id:6,title:'Finished chat',employee_name:'Test employee',status:'Closed by Management'}],
   reports:[{report_id:'TEST-REF',side:'reporter',latest_date:'2026-11-16',unread:1}]};
  await page.route('https://fixture.test/**',r=>{const u=new URL(r.request().url());
   if(u.pathname==='/')return r.fulfill({contentType:'text/html',body:'<meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="/acknowledgments.css"><link rel="stylesheet" href="/ack-communication.css">'+markup+'<script src="/acknowledgments.js"></script>'});
   if(u.pathname==='/acknowledgments.js')return r.fulfill({contentType:'text/javascript',body:read('assets/js/acknowledgments.js')});
   if(u.pathname.endsWith('.css'))return r.fulfill({contentType:'text/css',body:read('assets/css'+u.pathname)});
   if(u.searchParams.get('action')==='register')return r.fulfill({json:data});return r.fulfill({status:404,body:''});
  });
  await page.goto('https://fixture.test/');
  const tab=page.getByRole('button',{name:/Needs My Attention/}),rows=page.locator('#ack-table tbody tr[data-thread]');
  const count=async n=>tab.getByText(String(n),{exact:true}).waitFor();
  await count(3);await tab.click();assert.equal(await rows.count(),3);assert.equal(await page.locator('#ack-table tr.ack-needs-reply').count(),3);
  const q=page.locator('#ack-table [data-thread="2"]');data.records[1].unread=0;
  await page.evaluate(()=>window.dispatchEvent(new Event('ack:register-changed')));await q.locator('.ack-unread').waitFor({state:'detached'});await count(3);
  Object.assign(data.records[1],{status:'pending',has_clarification:1,latest_event_type:'reply'});data.reports[0].side='owner';
  await page.evaluate(()=>window.dispatchEvent(new Event('ack:register-changed')));await count(1);assert.equal(await rows.count(),1);
  Object.assign(data.chats[0],{status:'Awaiting Admin Response',unread:1,latest_date:'2026-11-17 11:00:00'});
  await count(2);assert.equal(await rows.first().getAttribute('data-thread'),'5'); // polling, no refresh or event
  await page.getByRole('button',{name:'All',exact:true}).click();
  assert.deepEqual(await page.locator('#ack-table .ack-status-done').allTextContents(),['Acknowledged','Closed by Management']);
  assert.equal(await q.locator('.ack-status-waiting').textContent(),'Awaiting Employee');
  assert.equal(await page.locator('[data-kind=report] .ack-status-ongoing').textContent(),'Conversation Ongoing');
  assert.equal(await page.locator('[data-thread="1"] .ack-status-pending').textContent(),'Pending');
  await page.close();console.log('PASS '+width+'px: count, unread vs action, deferral, explicit closure, automatic ordering, reply and all badge tones');
 }
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
