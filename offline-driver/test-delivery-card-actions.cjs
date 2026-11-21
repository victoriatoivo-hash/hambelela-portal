const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const flush=()=>new Promise(r=>setImmediate(r));
(async()=>{
 const row=(id,stage,action=null)=>({assignmentId:id,orderNumber:'QA-'+id,customerDisplayName:'Test <customer>',deliveryArea:'Test area',stage,action,displayStatus:stage,itemCount:1,paymentLabel:'Verified',scheduledTimeLocal:null});
 const data={driverId:3,dateLabel:'Today',dataStatus:'live',unreadNotifications:0,counts:{all:6,done:1},deliveries:[row(1,'to_collect','accept'),row(2,'to_collect','start'),{...row(3,'to_collect'),handoverNotice:'Waiting for Front Desk'},row(4,'on_route'),row(5,'done'),{...row(6,'exception'),reviewReason:'Review'}]};
 const dom=new JSDOM(`<main data-driver-deliveries data-driver-id="3" data-csrf="synthetic-token" data-endpoint="/feed" data-detail-base="/apps/delivery/index.php?delivery=" data-proof-base="/apps/delivery/index.php?proof=" data-summary="0"><p data-card-notice></p><p data-d-connection></p><p data-d-subtitle></p><input id="driver-search"><button data-d-clear></button><p data-d-review></p><p data-d-announcement></p><a data-d-bell></a><div data-d-results></div><script id="driver-deliveries-data" type="application/json">${JSON.stringify(data)}</script></main>`,{url:'https://driver.test/apps/delivery/index.php',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document,root=d.querySelector('main'),posts=[];let mode='ok',release,confirm=true;
 w.setInterval=()=>0;w.confirm=()=>confirm;w.fetch=async(url,opts={})=>{
   if(opts.method!=='POST')return {ok:true,status:200,json:async()=>JSON.parse(JSON.stringify(data))};
   posts.push({url,body:opts.body});if(mode==='held')await new Promise(r=>release=r);
   return {ok:true,status:200,json:async()=>{if(mode==='html')throw Error('HTML');return {ok:true,driverId:3,assignmentId:Number(new URL(url).searchParams.get('delivery'))}}};
 };
 for(const name of ['driver-delivery-card-actions','driver-deliveries'])w.eval(fs.readFileSync('assets/js/'+name+'.js','utf8'));
 const card=id=>d.querySelector(`[data-delivery-card="${id}"]`),submit=id=>card(id).querySelector('form').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
 assert.equal(card(1).querySelector('button').textContent,'Accept');assert.equal(card(2).querySelector('button').textContent,'Start');
 assert.equal(card(3).querySelector('button'),null);assert.match(card(3).textContent,/Waiting for Front Desk/);
 assert.equal(card(4).querySelector('[data-complete-delivery]').dataset.assignmentId,'4');assert.equal(card(5),null);assert.equal(card(6).querySelector('button'),null);
 assert.equal(d.querySelector('a form, a button, a a'),null,'no nested interactive controls');assert.equal(d.querySelector('customer'),null,'customer text escaped');
 confirm=false;submit(2);assert.equal(posts.length,0,'start requires driver receipt confirmation');confirm=true;
 mode='held';submit(1);await flush();submit(1);assert.equal(posts.length,1,'double accept blocked');assert.equal(root.dataset.actionSaving,'1');
 mode='html';release();await flush();await flush();assert.match(d.querySelector('[data-card-notice]').textContent,/did not confirm/);
 const key=posts[0].body.get('request_id');assert.ok(key);mode='ok';submit(1);await flush();await flush();
 assert.equal(posts[1].body.get('request_id'),key,'unknown result retry reuses key even after list refresh');assert.equal(posts[1].body.get('csrf'),'synthetic-token');assert.equal(posts[1].body.get('actor'),'3');
 submit(2);await flush();await flush();assert.equal(posts[2].body.get('action'),'start');assert.equal(posts[2].body.get('parcels_received'),'1');
 assert.equal(w.location.search,'','no list navigation after actions');assert.equal(root.dataset.actionSaving,'0');
 Object.defineProperty(w.navigator,'onLine',{value:false,configurable:true});submit(1);assert.equal(posts.length,3,'offline blocks writes');
 dom.window.close();console.log('PASS delivery cards: next-step buttons, waiting/review guards, escaped labels, no nested controls, confirmation, double tap, HTML failure, retry UUID, CSRF, same-page updates and offline writes.');
})().catch(e=>{console.error(e);process.exitCode=1});
