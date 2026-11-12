import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source = fs.readFileSync(new URL('../assets/js/portal-presence.js',import.meta.url),'utf8');
function tab() {
  const handlers={}, fields={}, jumps=[], reads=[];
  const leaf=()=>({dataset:{},classList:{add(){}},addEventListener(){},append(){},setAttribute(){}});
  const status={...leaf(),dataset:{presenceEndpoint:'/presence'},querySelector(k) {
    if(k.startsWith('.portal-notification') || k.startsWith('[data-notification')) return null;
    return fields[k]??=leaf();
  }};
  const doc={visibilityState:'visible',body:leaf(),title:'Portal',
    querySelector:k=>k==='[data-portal-header-status]'?status:null,
    addEventListener:(k,fn)=>{handlers[k]=fn;}};
  const win={setInterval(){},addEventListener:(k,fn)=>{handlers[k]=fn;}};
  let reply={ok:true,employees:[]},code=200;
  vm.runInNewContext(source,{document:doc,window:win,URL,Intl,Date,
    location:{origin:'https://portal.example',pathname:'/orders',search:'?day=1',replace:u=>jumps.push(u)},
    requestAnimationFrame:fn=>fn(),fetch:async()=>{reads.push(1);return {ok:code===200,status:code,json:async()=>reply};}});
  return {handlers,doc,fields,jumps,reads,response:(data,statusCode=200)=>{reply=data;code=statusCode;}};
}
const flush=()=>new Promise(r=>setImmediate(r));
const t=tab();await flush();
t.response({ok:true,employees:[{id:1,name:'Test One',presence:'offline',last_seen_at:'2026-11-12 09:30:00'}]});
await t.handlers.online();
assert.equal(t.fields['[data-portal-online-count]'].textContent,'0 online');
assert.match(t.fields['[data-portal-online-list]'].innerHTML,/Offline · Last seen 2026-11-12 09:30/);
t.response({ok:true,resume_dashboard:true,dashboard:'/index.php'});
t.doc.visibilityState='hidden';await t.handlers.online();assert.equal(t.jumps.length,0);
t.doc.visibilityState='visible';await t.handlers.online();assert.equal(t.jumps[0],'https://portal.example/index.php');
const draft=tab();await flush();
draft.handlers.input({target:{matches:()=>true}});
draft.response({ok:true,resume_dashboard:true,dashboard:'/index.php'});await draft.handlers.online();
assert.equal(draft.jumps.length,0);
const expired=tab();await flush();
expired.response({ok:false,resume_dashboard:true,dashboard:'/login.php'},401);await expired.handlers.online();
assert.equal(new URL(expired.jumps[0]).searchParams.get('return'),'/orders?day=1');
const external=tab();await flush();external.response({ok:true,resume_dashboard:true,dashboard:'https://invalid.example'});await external.handlers.online();
assert.equal(external.jumps.length,0);
console.log('Presence UI: offline display, background return, draft protection and same-origin redirects passed.');
