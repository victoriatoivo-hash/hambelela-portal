const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/js/driver-delivery-history.js','utf8');
function setup(){
 const nodes={},events={},windows={},timers=new Map(),requests=[],releases=[];let seq=0,mode='ok';
 function element(id=''){return {id,children:[],handlers:{},dataset:{},hidden:false,open:false,disabled:false,textContent:'',value:'',classList:{toggle(){}},addEventListener(k,f){this.handlers[k]=f},replaceChildren(){this.children=[];this._html=''},append(n){this.children.push(n)},get innerHTML(){return this._html||''},set innerHTML(v){this._html=v;for(const m of v.matchAll(/<(?:input|select|div)[^>]*id="([^"]+)"[^>]*>/g)){const n=node(m[1]);n.value=m[0].match(/value="([^"]*)"/)?.[1]||''}},focus(){document.activeElement=this},showModal(){this.open=true},close(){this.open=false;this.handlers.close?.()}}}
 const node=id=>nodes[id]??=element(id),root=node('root');root.dataset={actor:'3',base:'',endpoint:'/apps/delivery/index.php?history=1',csrf:'test-only'};root.querySelector=()=>node('more');
 const document={hidden:false,activeElement:null,querySelector:()=>root,getElementById:node,createElement:()=>element(),addEventListener:(k,f)=>events[k]=f};
 const row=i=>({event_id:i,outcome:i%2?'delivered':'issue',recorded_at_utc:'2026-11-21T08:00:00Z',local_date:'2026-11-21',local_time:'10:00',day_label:'21 November 2026',reference:'LAB-'+i,customer:i===1?'<script>unsafe</script>':'Practice R.',area:'Test area',context_visible:true});
 const data={filters:{period:'week',status:'all',search:'',from:'2026-11-16',to:'2026-11-22',today:'2026-11-21'},totals:{total_deliveries:2,completed:2,issues_raised:2,total_outcomes:4,needs_review:0,context_revision:100},items:[row(1),row(2)],cursor_next:'next',snapshot_event_id:20};
 const context={document,window:{addEventListener:(k,f)=>windows[k]=f},location:{reload(){}},URLSearchParams,AbortController,setTimeout:(f,ms)=>{const k=++seq;timers.set(k,{f,ms});return k},clearTimeout:k=>timers.delete(k),fetch:async(url,opts)=>{
  const p=JSON.parse(opts.body.get('payload'));requests.push({url,opts,p});const capture=mode;
  const result=p.action==='detail'?{...row(p.event_id),current_status:'completed',evidence:[],decisions:[]}:JSON.parse(JSON.stringify(data));
  if(p.cursor){result.items=[row(3),row(4)];result.cursor_next=null;}
  if(capture==='hold')await new Promise(r=>releases.push(r));if(capture==='offline')throw Error('Offline');if(capture==='timeout'){const e=Error();e.name='AbortError';throw e;}
  return {ok:capture!=='denied',status:capture==='denied'?403:200,json:async()=>{if(capture==='html')throw Error('Invalid JSON');return {ok:true,actor:capture==='identity'?4:3,data:result}}};
 }};vm.runInNewContext(source,context);
 return {node,data,requests,events,windows,document,setMode:v=>mode=v,release:()=>releases.shift()(),poll:()=>[...timers.values()].find(t=>t.ms!==20000)?.f(),row:()=>node('hh-list').children[0]?.children[1]?.children[0],cards:()=>node('hh-list').children.flatMap(g=>g.children[1].children),button:text=>node('hh-dialog-actions').children.find(n=>n.textContent===text)};
}
const flush=()=>new Promise(r=>setImmediate(r));let checks=0;function check(v,label){assert.ok(v,label);checks++;}
(async()=>{
 const x=setup();await flush();check(x.node('hh-total').textContent==='02','server totals');check(x.row().innerHTML.includes('&lt;script&gt;'),'HTML escaped');const original=x.row();await x.poll();check(x.row()===original,'unchanged refresh preserves cards');
 await x.node('hh-more-results').onclick();check(x.cards().length===4&&x.node('hh-more-results').hidden,'more pages append once');const expanded=x.row();await x.poll();check(x.cards().length===4&&x.row()===expanded,'background refresh preserves expanded pages');
 x.setMode('hold');const pending=x.row().onclick();await flush();x.node('hh-close').onclick();x.node('hh-search-button').onclick();x.release();await pending;check(x.node('hh-dialog-title').textContent==='Search delivery history','late detail cannot replace newer modal');
 x.setMode('ok');x.node('hh-query').value='Private Name';await x.button('Search').onclick();await flush();check(x.requests.at(-1).p.search==='Private Name'&&!x.requests.at(-1).url.includes('Private'),'search kept in POST body');
 check(x.requests.every(r=>['list','detail'].includes(r.p.action)&&r.opts.cache==='no-store'),'all calls read-only and no-store');
 const race=setup();await flush();race.setMode('hold');const slow=race.poll();await flush();race.node('hh-status').onclick();race.node('hh-outcome').value='issue';race.data.totals.completed=0;race.data.totals.context_revision=200;race.setMode('ok');race.button('Apply').onclick();await flush();race.release();await slow;check(race.node('hh-delivered').textContent==='00','late filter response ignored');
 const modal=setup();await flush();modal.node('hh-date').onclick();modal.poll();modal.node('hh-close').onclick();await modal.poll();check(modal.requests.length===2,'refresh resumes after modal');
 for(const mode of ['offline','timeout','html']){const e=setup();await flush();e.setMode(mode);await e.poll();check(e.cards().length===0&&e.node('hh-total').textContent==='—'&&!e.node('hh-retry').hidden,'fail closed with retry: '+mode);}
 for(const mode of ['denied','identity']){const e=setup();await flush();e.setMode(mode);await e.poll();check(e.cards().length===0&&e.node('hh-search-button').disabled,'revocation purge: '+mode);}
 const rev=setup();await flush();rev.data.totals.context_revision++;await rev.node('hh-more-results').onclick();check(rev.cards().length===0&&rev.node('hh-total').textContent==='—','changed access between pages clears old customer context');
 const hidden=setup();await flush();hidden.setMode('hold');const inflight=hidden.poll();await flush();hidden.document.hidden=true;hidden.events.visibilitychange();hidden.release();await inflight;check(hidden.cards().length===0,'hidden page and late response cannot retain old private history');hidden.document.hidden=false;hidden.setMode('ok');hidden.events.visibilitychange();await flush();check(hidden.cards().length===2,'visible page freshly authorizes');
 const gone=setup();await flush();gone.setMode('hold');const request=gone.poll();await flush();gone.windows.pagehide();gone.release();await request;check(gone.cards().length===0&&gone.node('hh-total').textContent==='—','bfcache/pagehide late response blocked');
 check(!/localStorage|sessionStorage|indexedDB|location\.(href|assign)/.test(source),'no persistent history cache or navigation mutations');
 console.log('PASS '+checks+' History client checks: races, read-only actions, escaping, private search, pagination, refresh stability, failed/slow API and permission revocation.');
})().catch(e=>{console.error(e);process.exitCode=1});
