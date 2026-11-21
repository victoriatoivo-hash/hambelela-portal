const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/js/driver-errands-phase7.js','utf8');
function setup(){
 const nodes={},win={},docEvents={},posted=[];let mode='ok',postMode='offline',poll,release,counter=0;
 const node=id=>nodes[id]??=( {id,dataset:{},handlers:{},hidden:false,disabled:false,open:false,textContent:'',writes:0,_html:'',get innerHTML(){return this._html},set innerHTML(v){this._html=v;this.writes++},replaceChildren(){this.innerHTML=''},setAttribute(k,v){this[k]=v},addEventListener(k,f){this.handlers[k]=f},focus(){document.activeElement=this},closest(){return null},showModal(){this.open=true},close(){this.open=false;this.handlers.close?.()},querySelector(){return null},querySelectorAll(){return []}} );
 const root=node('root');root.dataset={base:'',endpoint:'/errands',actor:'3',driver:'1',csrf:'local'};
 const counts=['assigned','in_progress','completed'].map(k=>{const n=node('count-'+k);n.dataset.count=k;return n});
 const tabs=['assigned','in_progress','completed'].map(k=>{const n=node('tab-'+k);n.dataset.tab=k;return n});
 root.querySelectorAll=q=>q==='[data-count]'?counts:q==='[data-tab]'?tabs:[];
 root.querySelector=q=>q==='.he-tabs'?node('tabs'):q==='[data-request-count]'?node('request-count'):null;
 const close=node('close'),submitButton=node('submit'),dialog=node('errands-dialog'),body=node('errands-modal-body');
 dialog.querySelector=()=>close;dialog.querySelectorAll=()=>[close,submitButton];
 const document={hidden:false,activeElement:close,querySelector:()=>root,getElementById:node,addEventListener:(k,f)=>docEvents[k]=f};
 class FD {constructor(form){this.entries=form?.entries||[]}*[Symbol.iterator](){yield* this.entries}get(k){return this.entries.find(x=>x[0]===k)?.[1]??null}getAll(k){return this.entries.filter(x=>x[0]===k).map(x=>x[1])}set(k,v){this.entries=this.entries.filter(x=>x[0]!==k);this.entries.push([k,v])}}
 const item={id:11,revision:1,reference:'ER-011',status:'assigned',title:'<script>unsafe</script>',place:'Training office',instructions:'Count every training box.',policy:{priority:true,items:[{name:'Boxes',expected:2000,unit:'boxes'}]},due:null,overdue:false};
 const feed={ok:true,actor:3,counts:{assigned:1,in_progress:0,completed:0},items:[item],requests:[],today:'20 November'};
 const navigator={onLine:true};const ctx={document,navigator,window:{addEventListener:(k,f)=>win[k]=f},crypto:{randomUUID:()=>`uuid-${++counter}`},FormData:FD,URL:{createObjectURL:()=> 'blob:test',revokeObjectURL(){}},URLSearchParams,AbortController,setTimeout,clearTimeout,setInterval:f=>(poll=f,1),location:{search:'',reload(){}},fetch:async(url,options)=>{
  if(options.method==='POST'){posted.push(JSON.parse(options.body.get('payload')));if(postMode==='offline')throw Error('Connection lost');if(postMode==='timeout'){const e=Error();e.name='AbortError';throw e}if(postMode==='hold')await new Promise(r=>release=r);if(postMode==='html')return {status:200,ok:true,json:async()=>{throw Error('not JSON')}};if(postMode==='stale')return {status:409,ok:false,json:async()=>({ok:false,error:'This errand changed. Review the latest details.'})};return {status:200,ok:true,json:async()=>({ok:true,actor:3,result:{status:'pending',receipt:42,saved_at:'2026-11-20 12:30:00'}})};}
  if(mode==='network')throw Error('No connection');return {status:mode==='denied'?403:200,ok:true,json:async()=>({...feed,actor:mode==='identity'?4:3})};
 }};
 vm.runInNewContext(source,ctx);
 const click=b=>root.handlers.click({target:{closest:()=>b}});
 const button=(id,dataset={})=>({id,dataset,matches:s=>s==='[data-tab]'?!!dataset.tab:s==='[data-close]'?id==='close':false});
 const request=()=>click(button('errands-add'));
 const form={dataset:{action:'request'},entries:[['title','Training errand request'],['place','Training office'],['reason','Collect the synthetic training boxes.']],reportValidity:()=>true,querySelector:()=>null};
 const submit=()=>body.handlers.submit({preventDefault(){},target:form});
 return {node,dialog,body,counts,posted,win,navigator,request,submit,poll:()=>poll(),setMode:v=>mode=v,setPost:v=>postMode=v,release:()=>release(),close:()=>click(button('close')),feed,form};
}
const flush=()=>new Promise(r=>setImmediate(r));
(async()=>{
 const a=setup();await flush();assert.equal(a.counts[0].textContent,'01');assert.match(a.node('errands-list').innerHTML,/&lt;script&gt;/);const writes=a.node('errands-list').writes;await a.poll();assert.equal(a.node('errands-list').writes,writes,'unchanged refresh does not replace cards');
 a.request();assert.equal(a.dialog.open,true);assert.match(a.body.innerHTML,/Unsent draft/);assert.equal(a.posted.length,0,'opening draft sends nothing');
 a.submit();await flush();assert.match(a.node('errands-dialog-error').textContent,/Connection/);assert.match(a.body.innerHTML,/Request approval/);const key=a.posted[0].uuid;
 a.setPost('timeout');a.submit();await flush();assert.match(a.node('errands-dialog-error').textContent,/Timed out/);assert.equal(a.posted.at(-1).uuid,key,'uncertain retry keeps request key');
 a.setPost('html');a.submit();await flush();assert.match(a.node('errands-dialog-error').textContent,/No confirmation/);
 a.setPost('stale');a.submit();await flush();assert.match(a.node('errands-dialog-error').textContent,/changed/);assert.equal(a.counts[0].textContent,'01','failed submission cannot change workload');
 a.setPost('hold');a.submit();const n=a.posted.length;a.submit();assert.equal(a.posted.length,n,'double click posts once');assert.equal(a.node('submit').disabled,true);a.release();await flush();await flush();assert.match(a.body.innerHTML,/Request saved for dispatch approval/);assert.equal(a.counts[0].textContent,'01','pending request never self assigns');
 a.close();a.navigator.onLine=false;a.win.offline();assert.match(a.node('errands-sync').textContent,/Offline/);a.navigator.onLine=true;a.setMode('network');await a.poll();assert.match(a.node('errands-error').textContent,/No connection/);assert.equal(a.counts[0].textContent,'01','network failure cannot fabricate zeros');
 a.setMode('ok');await a.poll();assert.equal(a.node('errands-error').hidden,true);
 for(const mode of ['denied','identity']){const x=setup();await flush();x.request();x.setMode(mode);await x.poll();assert.equal(x.node('errands-list').innerHTML,'');assert.equal(x.dialog.open,false);assert.equal(x.counts[0].textContent,'—');}
 assert.doesNotMatch(source,/localStorage|sessionStorage|console\./);assert.match(source,/15000/);assert.match(fs.readFileSync('assets/css/driver-errands-integration.css','utf8'),/prefers-reduced-motion/);
 console.log('PASS Errands client: stable refresh, escaping, explicit unsent drafts, preserved retry key, timeout/HTML/stale/network errors, double-click protection, server-only success/counters, no self-assignment, recovery, identity/session purge, no persistent PII, reduced-motion support.');
})().catch(e=>{console.error(e);process.exitCode=1});
