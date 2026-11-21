const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const source=name=>fs.readFileSync('assets/js/'+name+'.js','utf8');
let checks=0;
const check=(value,message)=>{assert.ok(value,message);checks++;};
function page(html){
 const dom=new JSDOM(html,{url:'https://driver.example.test/apps/delivery/index.php',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,timers=[];w.setInterval=(fn,ms)=>{timers.push({fn,ms});return timers.length;};
 w.eval(source('driver-live-updates'));return {w,d:w.document,timers};
}
const flush=()=>new Promise(r=>setImmediate(r));
(async()=>{
 const {w,d}=page('<div class="ho-driver-dashboard__scroll"><main id="list"><section data-group="active"><a href="?delivery=1"><strong>One</strong></a><a href="?delivery=2"><strong>Two</strong></a></section></main></div>');
 const list=d.querySelector('#list'),one=list.querySelector('a'),two=list.querySelectorAll('a')[1],scroller=list.parentNode;
 scroller.scrollTop=150;two.focus();let clicks=0;two.addEventListener('click',e=>{e.preventDefault();clicks++;});
 const observer=new w.MutationObserver(()=>{});observer.observe(list,{childList:true,subtree:true,attributes:true,characterData:true});
 w.hambelelaLiveUpdates.html(list,list.innerHTML);check(observer.takeRecords().length===0,'unchanged markup produces zero DOM mutations');
 w.hambelelaLiveUpdates.html(list,'<section data-group="active"><a href="?delivery=3"><strong>New order</strong></a><a href="?delivery=1"><strong>One</strong></a><a href="?delivery=2"><strong>On route</strong></a></section>');
 check(list.querySelectorAll('a')[1]===one&&list.querySelectorAll('a')[2]===two,'new order keeps existing cards');
 check(two.textContent==='On route'&&d.activeElement===two,'changed status keeps focused control');
 check(scroller.scrollTop===150,'scroll position retained');two.click();check(clicks===1,'existing event listeners retained');
 w.hambelelaLiveUpdates.html(list,'<section data-group="active"><a href="?delivery=2"><strong>Done</strong></a></section>');
 check(!one.isConnected&&two.isConnected&&two.textContent==='Done','removed assignments disappear without rebuilding retained card');
 list.innerHTML='<details id="notes" open><summary>Notes</summary><p>Old</p></details><textarea id="draft"></textarea><select id="choice"><option value="a">A</option><option value="b">B</option></select><canvas id="signature"></canvas><div data-google-canvas><b>Actual map</b></div><span data-map-duration>5 minutes</span>';
 const details=list.querySelector('details'),draft=list.querySelector('textarea'),select=list.querySelector('select'),canvas=list.querySelector('canvas'),map=list.querySelector('[data-google-canvas]');
 draft.value='Unsent reply';draft.focus();draft.setSelectionRange(2,5);select.value='b';
 w.hambelelaLiveUpdates.html(list,'<details id="notes"><summary>Notes</summary><p>Updated</p></details><textarea id="draft">Server default</textarea><select id="choice"><option value="a">A</option><option value="b">B updated</option></select><canvas id="signature"></canvas><div data-google-canvas>Placeholder</div><span data-map-duration>Unavailable</span>');
 check(details.open&&details.querySelector('p').textContent==='Updated','expanded details survive content update');
 check(draft.value==='Unsent reply'&&draft.selectionStart===2&&draft.selectionEnd===5&&d.activeElement===draft,'draft, selection and focus survive');
 check(select.value==='b'&&select.options[1].text==='B updated','selection retained while options refresh');
 check(list.querySelector('canvas')===canvas&&list.querySelector('[data-google-canvas]')===map&&map.textContent==='Actual map','canvas/signature/map not rebuilt');
 check(list.querySelector('[data-map-duration]').textContent==='5 minutes','map estimate not overwritten by poll placeholders');
 w.close();

 const initial={driverId:3,dateLabel:'Today',dataStatus:'live',unreadNotifications:0,counts:{all:1},deliveries:[{assignmentId:7,orderNumber:'TEST-7',customerDisplayName:'Test customer',deliveryArea:'Synthetic',stage:'to_collect',displayStatus:'At store',itemCount:1,paymentLabel:'Paid',scheduledTimeLocal:'10:00'}]};
 const board=page(`<main data-driver-deliveries data-driver-id="3" data-endpoint="/feed" data-detail-base="?delivery="><div class="ho-driver-dashboard__scroll"><p data-d-subtitle></p><input id="driver-search"><button data-d-clear></button><p data-d-connection></p><p data-d-review></p><span data-d-count="active"></span><div data-d-results></div><p data-d-announcement></p><a data-d-bell></a></div></main><script id="driver-deliveries-data" type="application/json">${JSON.stringify(initial)}</script>`);
 let result=initial,requests=0,wait=null;
 board.w.fetch=async()=>{requests++;if(wait)await wait;return {ok:true,status:200,json:async()=>result};};
 board.w.eval(source('driver-deliveries'));const poll=board.timers.find(t=>t.ms===15000).fn;
 const card=board.d.querySelector('[data-d-results] a'),search=board.d.querySelector('#driver-search');
 search.value='Test';search.dispatchEvent(new board.w.Event('input'));card.focus();
 result={...initial,counts:{all:2},deliveries:[...initial.deliveries,{...initial.deliveries[0],assignmentId:8,orderNumber:'TEST-8'}]};await poll();
 check(board.d.querySelectorAll('[data-d-results] a').length===2,'new assignment appears from timer without manual refresh');
 check(board.d.querySelector('[data-d-results] a')===card&&search.value==='Test'&&board.d.activeElement===card,'new assignment preserves card, search and focus');
 let resolve;wait=new Promise(r=>resolve=r);const pending=poll(),before=requests;await poll();check(requests===before,'no overlapping refresh requests');resolve();await pending;wait=null;
 result={...result,driverId:4};await poll();check(!board.d.querySelector('[data-d-results] a'),'identity change clears private orders');board.w.close();

 const issue=page('<main data-issue data-endpoint="/issue" data-preview="false"><p id="issue-error" hidden></p><section data-issue-receipt data-actor="3" data-assignment="7"><h2>Awaiting dispatch</h2><details open><summary>History</summary></details></section></main>');
 let receiptHtml='<section data-issue-receipt data-actor="3" data-assignment="7"><h2>Retry approved</h2><details><summary>History</summary><p>Dispatch response</p></details></section>';
 issue.w.fetch=async()=>({ok:true,status:200,text:async()=>receiptHtml});issue.w.eval(source('driver-delivery-issue'));
 const receipt=issue.d.querySelector('[data-issue-receipt]'),expanded=receipt.querySelector('details');await issue.timers[0].fn();
 check(receipt.querySelector('h2').textContent==='Retry approved','dispatch decision arrives automatically');
 check(receipt.querySelector('details')===expanded&&expanded.open,'receipt retains open history');
 receiptHtml=receiptHtml.replace('data-actor="3"','data-actor="4"');await issue.timers[0].fn();check(receipt.hidden&&!receipt.textContent,'receipt purges on identity mismatch');issue.w.close();
 console.log('PASS '+checks+' real-DOM background update checks');
})().catch(e=>{console.error(e);process.exitCode=1;});
