const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/js/driver-dashboard.js','utf8');let checks=0;
function node(text=''){return {textContent:text,dataset:{},children:[],setAttribute(){},append(...n){this.children.push(...n);},replaceChildren(...n){this.children=n;},close(){this.open=false;},get childNodes(){return this.children;},get outerHTML(){return this.textContent;}};}
const text=n=>n.textContent+n.children.map(text).join(' ');
function setup(){
 const root=node(),body=node('CUSTOMER PRIVATE CASH N$100'),notice=node();root.dataset={driverId:'3',preview:'1',csrf:'old'};root.children=[body,notice];
 root.querySelector=k=>k==='.ho-driver-dashboard__body'?root.children.find(n=>n.textContent.startsWith('CUSTOMER'))||null:k==='[data-hd-network]'?notice:null;
 root.querySelectorAll=()=>[];body.replaceWith=n=>root.children.splice(root.children.indexOf(body),1,n);
 const docs={},wins={},timers=[],channels=[];let count=0,response=async()=>({ok:true,status:200,redirected:false,text:async()=> 'valid'});
 const document={hidden:false,querySelector:()=>root,createElement:()=>node(),importNode:n=>n,addEventListener:(k,f)=>docs[k]=f};
 class Parser{parseFromString(html){const next=node();next.dataset={driverId:html==='other'?'4':'3',csrf:'new'};next.children=[node('CUSTOMER PRIVATE CASH N$100'),node()];next.querySelector=k=>k==='.ho-driver-dashboard__body'?next.children[0]:null;return {querySelector:()=>html==='login'?null:next};}}
 const window={addEventListener:(k,f)=>wins[k]=f};
 const context={document,window,navigator:{onLine:true},location:{href:'http://localhost/index.php',reload(){}},DOMParser:Parser,AbortController,Intl,Date,console,setTimeout,clearTimeout,setInterval:(f,ms)=>timers.push([f,ms]),BroadcastChannel:class{constructor(){channels.push(this);}},fetch:async(url,options)=>{assert.equal(options.cache,'no-store');count++;return response();}};
 vm.runInNewContext(source,context);
 return {root,document,context,docs,wins,channels,refresh:timers.find(t=>t[1]===15000)[0],respond:f=>response=f,count:()=>count};
}
function check(v,label){assert.ok(v,label);checks++;}
(async()=>{
 let s=setup(),original=s.root.children[0];await s.refresh();check(s.root.children[0]===original,'unchanged DOM is stable');check(s.root.dataset.csrf==='new','fresh token used');
 s.wins.focus();check(s.root.children[0]===original,'ordinary focus never blanks the dashboard');await new Promise(r=>setImmediate(r));check(s.root.children[0]===original,'verified focus keeps existing nodes');
 for(const status of [401,403,500]){s=setup();s.respond(async()=>({ok:false,status}));await s.refresh();check(!text(s.root).includes('CUSTOMER'),'HTTP '+status+' clears private data');}
 for(const html of ['other','login']){s=setup();s.respond(async()=>({ok:true,status:200,text:async()=>html}));await s.refresh();check(!text(s.root).includes('PRIVATE'),html+' purges private data');await s.refresh();check(s.count()===1,'invalid identity cannot autorestore');}
 s=setup();s.respond(async()=>({ok:true,redirected:true}));await s.refresh();check(!text(s.root).includes('PRIVATE'),'login redirect cleared');
 s=setup();s.respond(async()=>{throw Error('network');});await s.refresh();check(!text(s.root).includes('PRIVATE'),'network failure clears data');s.respond(async()=>({ok:true,text:async()=>'valid'}));await s.refresh();check(text(s.root).includes('PRIVATE'),'recovery requires verified response');
 s=setup();let resolve;s.respond(()=>new Promise(r=>resolve=r));const flight=s.refresh();s.document.hidden=true;s.docs.visibilitychange();check(!text(s.root).includes('PRIVATE'),'hidden DOM purged');resolve({ok:true,text:async()=>'valid'});await flight;check(!text(s.root).includes('PRIVATE'),'late response cannot restore hidden data');
 s=setup();s.wins.pagehide();check(!text(s.root).includes('PRIVATE'),'pagehide purged');s.wins.pageshow({persisted:true});await new Promise(r=>setImmediate(r));check(text(s.root).includes('PRIVATE'),'bfcache restoration revalidates');
 s=setup();s.context.navigator.onLine=false;s.wins.offline();await s.refresh();check(!text(s.root).includes('PRIVATE')&&s.count()===0,'offline clears and never fetches');
 s=setup();s.channels[0].onmessage({data:'signout'});await s.refresh();check(!text(s.root).includes('PRIVATE')&&s.count()===0,'other-tab signout cannot race session destruction');
 s=setup();s.respond(async()=>({ok:false,status:403}));s.wins.focus();await new Promise(r=>setImmediate(r));check(!text(s.root).includes('PRIVATE'),'focus after other-tab logout revalidates');
 console.log('PASS '+checks+' dashboard privacy checks');
})().catch(e=>{console.error(e);process.exitCode=1;});
