const {JSDOM}=require('jsdom'),assert=require('node:assert/strict'),fs=require('node:fs');
const src=fs.readFileSync(require.resolve('../assets/js/acknowledgments.js'),'utf8');
(async()=>{
 const ids='drawer content table message summary tabs search type-filter sort deadline-filter clear prev next page'.split(' ');
 const dom=new JSDOM(ids.map(id=>'<div id="ack-'+id+'"></div>').join(''),{url:'https://example.test/register',runScripts:'outside-only'});
 const w=dom.window,d=w.document;
 try{
  for(const id of ['search','type-filter','sort','deadline-filter'])d.querySelector('#ack-'+id).value='';
  const data={owner:true,csrf:'test',records:[],chats:[{id:7,can_remove:true,last_id:45,employee_name:'Test'},{id:9,can_remove:false,last_id:46}],assistance:[{topic:7,can_remove:true,message_id:82,employee_name:'Test'}],reports:[{report_id:'reference',side:'owner'}]};
  let confirmed=false,opened=0,requests=[];w.confirm=()=>confirmed;w.AckResponseChat={open:()=>opened++};
  w.fetch=async(url,opts)=>{
   const action=url.split('=')[1];
   if(action==='register')return Response.json(data);
   const body=JSON.parse(opts.body);requests.push([action,body,opts.headers['X-CSRF-Token']]);
   if(action==='chat_thread')data.chats=data.chats.filter(x=>x.id!==body.id);
   if(action==='assistance_dialog')data.assistance=[];
   return Response.json({removed:true});
  };
  w.eval(src);const tick=()=>new Promise(r=>setTimeout(r,5));await tick();
  const sel=(kind,id)=>d.querySelector('[data-kind="'+kind+'"][data-thread="'+id+'"]');
  const btn=(kind,id)=>sel(kind,id)?.querySelector('[data-delete-chat]');
  assert.deepEqual([...sel('chat',7).querySelectorAll('button')].map(b=>b.textContent),['View','Delete']);
  assert.equal(btn('chat',9),null);assert.equal(btn('report','reference'),null);
  btn('chat',7).click();assert.equal(requests.length,0);assert.equal(opened,0);
  confirmed=true;btn('chat',7).click();await tick();assert.equal(sel('chat',7),null);
  assert.ok(btn('assistance',7));btn('assistance',7).click();await tick();
  assert.equal(sel('assistance',7),null);assert.equal(opened,0);
  assert.deepEqual(requests,[['chat_thread',{id:7,op:'delete_for_me',expected:45},'test'],['assistance_dialog',{id:7,op:'delete_for_me',expected:82},'test']]);
  console.log('PASS: list buttons, owner-only capability, cancel, both chat types, isolated IDs and no conversation opened.');
 }finally{w.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
