// Actual popup script, local portal styles and font; synthetic two-role thread only.
const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
const read=p=>fs.readFileSync(p,'utf8');
const css=['portal','acknowledgments','ess-dashboard','ack-communication','portal-responsive'].map(k=>read('assets/css/'+k+'.css')).join('\n');
const notices=process.env.NOTICE_BASE_CSS?read(process.env.NOTICE_BASE_CSS):'';
const script=read('assets/js/acknowledgments-popup.js');
const fixture='<main class="workspace ack-contact-page"><form><label>Private message<textarea></textarea></label></form><div id="ack-private"><p class="ack-thread-message" data-sender="owner">Posted reply</p></div><div id="anonymous"><textarea></textarea><p id="unnamed-answer"><span>Reply</span></p></div></main><div class="hb-ack-page"><dialog id="ack-drawer"><textarea></textarea><article class="ack-bubble management">Posted message</article><div class="ack-material"><textarea></textarea></div></dialog></div><div class="ack-private-modal"><p class="ack-private-body" data-sender="employee">Private question</p><label><textarea></textarea></label></div><div class="ack-company-notice ack-report-dialog"><form><label><textarea></textarea></label></form><div class="ack-report-history"><article class="ack-report-bubble is-owner"><p>Report reply</p></article></div></div>';
async function style(loc){return loc.evaluate(e=>{const s=getComputedStyle(e);return [s.fontFamily.split(',')[0].trim(),s.fontSize,s.fontWeight,s.color];});}
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_BIN});
 try{for(const width of [390,1440]){
  let event={id:100,event_type:'clarify',actor_name:'Staff',message:'Which shelf?',created_at:'2026-11-16 09:00'},events=[event];
  async function open(role){
   const p=await browser.newPage({viewport:{width,height:800}});p.setDefaultTimeout(6000);
   await p.route('https://fixture.test/**',async route=>{
    const u=new URL(route.request().url()),action=u.searchParams.get('action');
    if(u.pathname==='/')return route.fulfill({contentType:'text/html; charset=utf-8',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+notices+css+'</style>'+fixture+'<script src="/popup.js"></script>'});
    if(u.pathname==='/popup.js')return route.fulfill({contentType:'text/javascript; charset=utf-8',body:script});
    if(u.pathname.endsWith('jost-variable.woff2'))return route.fulfill({contentType:'font/woff2',body:fs.readFileSync('assets/fonts/jost-variable.woff2')});
    let json={};if(action==='clarification_poll')json={csrf:'test',turn:(event.event_type==='clarify')===(role==='admin')?{event_id:event.id,recipient_id:17,event_type:event.event_type}:null};
    if(action==='detail')json={status:event.event_type==='clarify'?'clarification':'pending',events,title:'Dispatch note',message_html:'Check the shelf.',employee_name:'Staff'};
    if(action==='respond'){const b=route.request().postDataJSON();event={id:event.id+1,event_type:b.action,actor_name:role,message:b.message,created_at:'2026-11-16 09:01'};events.push(event);json={ok:true};}
    return route.fulfill({json});
   });
   await p.goto('https://fixture.test/');return p;
  }
  const input=['Jost',width<=700?'16px':'12px','400','rgb(28, 33, 24)'],body=['Jost','12px','400','rgb(28, 33, 24)'];
  const admin=await open('admin'),dialog=admin.locator('.ack-thread-notice[open]');
  await dialog.waitFor();await admin.evaluate(()=>document.fonts.load('400 12px Jost'));
  const typed=dialog.getByRole('textbox',{name:'Your message'});await typed.fill('Use the top shelf.');
  assert.deepEqual(await style(typed),input);assert.deepEqual(await style(admin.locator('.ack-contact-page form textarea')),input);
  for(const selector of ['#ack-drawer textarea','#anonymous textarea','.ack-private-modal textarea','.ack-report-dialog textarea'])for(const field of await admin.locator(selector).all())assert.deepEqual(await style(field),input,selector);
  for(const selector of ['#ack-drawer .ack-bubble','#unnamed-answer > span','#ack-private .ack-thread-message','.ack-private-body','.ack-report-bubble p'])assert.deepEqual(await style(admin.locator(selector)),body,selector);
  await dialog.getByRole('button',{name:'Send reply'}).click();await dialog.waitFor({state:'detached'});
  const employee=await open('employee'),reply=employee.locator('.ack-thread-notice[open]');await reply.waitFor();
  assert.deepEqual(await style(reply.locator('section p').filter({hasText:'Use the top shelf.'})),body);
  await reply.getByRole('button',{name:'I Still Don’t Understand'}).click();
  const follow=reply.getByRole('textbox',{name:'Your message'});await follow.fill('Which box on the top shelf?');assert.deepEqual(await style(follow),input);
  await reply.getByRole('button',{name:'Send clarification'}).click();await reply.waitFor({state:'detached'});
  assert.deepEqual(await style(dialog.locator('section p').filter({hasText:'Which box on the top shelf?'})),body);
  await employee.close();await admin.close();console.log(width+'px: Admin and Employee typing, both received messages and related fields passed');
 }}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
