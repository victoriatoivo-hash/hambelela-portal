const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const flush=()=>new Promise(r=>setImmediate(r));
(async()=>{
 for(const action of ['accept','confirm_pickup','start']){
  const html=`<main data-order-details data-driver-id="3" data-assignment-id="46" data-endpoint="/feed"><div class="ho-driver-dashboard__scroll"><p data-order-notice></p><div data-order-content><form data-driver-action action="/apps/delivery/index.php?delivery=46"><input name="csrf" value="test-token"><input name="actor" value="3"><input name="action" value="${action}"><button>Submit</button></form></div></div></main>`;
  const dom=new JSDOM(html,{url:'https://driver.example.test/',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window,d=w.document,form=d.querySelector('form'),posts=[];
  // jsdom lacks browser named-form shadowing. Model the native browser property explicitly.
  Object.defineProperty(form,'action',{value:form.elements.namedItem('action')});
  w.setInterval=()=>0;let bad=false;
  w.fetch=async(url,options)=>{
   if(options.method==='POST'){
    posts.push({url,data:options.body});
    return {ok:true,status:200,json:async()=>{if(bad)throw SyntaxError('Unexpected token <');return {ok:true,driverId:3,assignmentId:46};}};
   }
   return {ok:true,status:200,headers:{get:()=> 'application/json'},json:async()=>({dataStatus:'live',driverId:3,assignmentId:46,html:d.querySelector('[data-order-content]').innerHTML})};
  };
  w.eval(fs.readFileSync('assets/js/driver-order-details.js','utf8'));await flush();
  bad=true;form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await flush();
  assert.equal(posts[0].url,'/apps/delivery/index.php?delivery=46');assert.equal(posts[0].data.get('action'),action);assert.equal(posts[0].data.get('csrf'),'test-token');assert.equal(posts[0].data.get('actor'),'3');
  assert.match(d.querySelector('[data-order-notice]').textContent,/did not confirm/);assert.doesNotMatch(d.querySelector('[data-order-notice]').textContent,/JSON|Unexpected token/);
  const retry=posts[0].data.get('request_id');assert.ok(retry);bad=false;
  form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await flush();await flush();
  assert.equal(posts[1].data.get('request_id'),retry);assert.equal(d.querySelector('[data-order-notice]').textContent,'');
  dom.window.close();
 }
 console.log('PASS accept/pickup/start: named action control cannot shadow request URL; CSRF/actor/action preserved; HTML response handled; retry idempotency retained.');
})().catch(e=>{console.error(e);process.exitCode=1;});
