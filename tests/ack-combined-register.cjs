// UI acceptance with no production network, real employee accounts, or private reply codes.
const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict');
const read=p=>fs.readFileSync(p,'utf8'),php=read('apps/acknowledgments/index.php');
const markup=php.slice(php.indexOf('<main'),php.lastIndexOf('</main>')+7).replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g,'');
(async()=>{const browser=await chromium.launch({executablePath:process.env.CHROME_BIN});try{
 for(const width of [390,1440]){
  const page=await browser.newPage({viewport:{width,height:850}}),ref='ab'.repeat(16);
  const records=[1,2].map(id=>({id,employee_name:'Fixture staff',employee_id:2,title:'Notice '+id,version:1,created_at:'2026-11-12 09:10:20',status:id===1?'pending':'clarification',release_state:'released',latest_message:'Named preview',has_clarification:id===2?1:0}));
  const messages=[{side:'reporter',body:'Legacy original',submitted_on:null},{side:'reporter',body:'New follow-up',submitted_on:'2026-11-16'}];
  let unread=1,alert=false;const errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://fixture.test/**',async route=>{const u=new URL(route.request().url()),a=u.searchParams.get('action');
   if(u.pathname==='/')return route.fulfill({contentType:'text/html; charset=utf-8',body:markup+'<script src="/ack-communication.js"></script><script src="/acknowledgments.js"></script><script src="/acknowledgments-popup.js"></script>'});
   if(u.pathname.endsWith('.js'))return route.fulfill({contentType:'text/javascript; charset=utf-8',body:read('assets/js'+u.pathname)});
   let out={};const b=route.request().postDataJSON()||{};
   if(a==='register')out={owner:true,csrf:'x',records,reports:[{report_id:ref,side:messages.at(-1).side,latest_message:messages.at(-1).body,submitted_date:null,latest_date:'2026-11-16',unread}]};
   if(a==='report_thread'){assert.equal(b.report,ref);if(!b.dismiss){unread=0;if(b.message)messages.push({side:'owner',body:b.message,submitted_on:'2026-11-16'});}alert=false;out={messages};}
   if(a==='clarification_poll')out={csrf:'x',report:alert?{report_id:ref,message_id:3}:null};
   return route.fulfill({json:out});
  });
  await page.goto('https://fixture.test/');
  const area=page.locator('#ack-table'),row=area.locator('[data-kind=report]'),dialog=page.getByRole('dialog',{name:'Anonymous report'});
  await row.waitFor();assert.deepEqual(await area.locator('.ack-type').allTextContents(),['Anonymous Report','Acknowledgement','Clarification']);
  assert((await row.innerText()).includes('Date not recorded'));assert((await row.innerText()).includes('Unread'));
  await row.getByRole('button').click();await dialog.getByText('Legacy original').waitFor();
  await dialog.getByRole('textbox').fill('Owner response');await dialog.getByRole('button',{name:'Send reply'}).click();
  await dialog.locator('.ack-report-bubble.is-owner').getByText('Owner response').waitFor();
  assert.deepEqual(await dialog.locator('.ack-report-bubble p').allTextContents(),['Legacy original','New follow-up','Owner response']);
  await dialog.getByRole('button',{name:'Close',exact:true}).click();await row.getByText('Owner response').waitFor();assert(!(await row.innerText()).includes('Unread'));
  alert=true;messages.push({side:'reporter',body:'Second anonymous reply',submitted_on:'2026-11-16'});unread=1;
  await dialog.getByRole('button',{name:'Open conversation'}).waitFor();assert(!(await dialog.innerText()).includes('Second anonymous reply'));
  await dialog.getByRole('button',{name:'Open conversation'}).click();await dialog.getByText('Second anonymous reply').waitFor();
  assert.equal(page.url(),'https://fixture.test/');assert.deepEqual(errors,[]);await page.close();console.log(width+'px: combined table, chat, reply, unread and notification passed');
 }
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
