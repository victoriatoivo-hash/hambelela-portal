const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const nodes={},win={},inputs=[],requests=[];let selected='',mode='offline',redirect=null,release;
function node(id){return nodes[id]??=( {id,value:'',checked:false,disabled:false,hidden:false,textContent:'',handlers:{},files:[],addEventListener(k,f){this.handlers[k]=f},scrollIntoView(){},removeAttribute(k){delete this[k]},showModal(){this.open=true},close(){this.open=false},click(){return this.handlers.click?.({preventDefault(){}})}});}
['issue-error','issue-safety','issue-urgent','issue-photo-input','issue-thumbnail','issue-photo-preview','issue-add-photo','issue-remove-photo','issue-confirm-summary','issue-confirm-dialog','issue-confirm-send','issue-transfer','issue-submit'].forEach(node);
const root={dataset:{preview:'false',order:'SYNTHETIC-6',endpoint:'/apps/delivery/index.php?issue=6'}};
const form=node('issue-form');form.elements={notes:node('notes'),contact_attempted:node('contact'),urgent:node('urgent')};
const labels={not_answering:'Customer not answering',wrong_address:'Wrong or missing address',unavailable:'Customer unavailable',other:'Other issue'};
for(const value of Object.keys(labels)){const n=node(value);n.value=value;n.closest=()=>({querySelector:()=>({textContent:labels[value]})});inputs.push(n);}
form.querySelector=()=>selected?nodes[selected]:null;
const controls=[...inputs,...Object.values(form.elements),node('issue-submit'),node('issue-photo-input')];
form.querySelectorAll=selector=>selector.includes('name=')?inputs:controls;
const document={querySelector:()=>root,getElementById:id=>id==='issue-dispatch'?null:node(id),querySelectorAll:()=>[]};
class FD{constructor(){this.data={notes:form.elements.notes.value,reason:selected};}set(k,v){this.data[k]=v;}}
let counter=0;
const ctx={document,window:{addEventListener:(k,f)=>win[k]=f},crypto:{randomUUID:()=>`request-${++counter}`},FormData:FD,URL:{createObjectURL:()=> 'blob:local-test',revokeObjectURL(){}},AbortController,setTimeout,clearTimeout,location:{replace:x=>redirect=x},fetch:async(url,opts)=>{requests.push(opts.body.data);if(mode==='offline')throw Error('Network unavailable');if(mode==='delay')await new Promise(r=>release=r);return {ok:mode==='success'||mode==='delay',text:async()=>mode==='html'?'<html>Sign in</html>':JSON.stringify(mode==='success'||mode==='delay'?{ok:true}:{ok:false,error:'Delivery changed. Reload and review.'})};}};
vm.runInNewContext(fs.readFileSync('assets/js/driver-delivery-issue.js','utf8'),ctx);
const flush=()=>new Promise(r=>setImmediate(r));const submit=()=>form.handlers.submit({preventDefault(){}});const choose=v=>{selected=v;inputs.find(n=>n.value===v).handlers.change();};
(async()=>{
 submit();assert.match(node('issue-error').textContent,/Select what/);assert.equal(requests.length,0);
 choose('not_answering');form.elements.notes.value='Actual useful synthetic description.';submit();assert.match(node('issue-error').textContent,/attempted/);
 choose('unavailable');submit();assert.match(node('issue-error').textContent,/attempted/);
 choose('wrong_address');submit();assert.equal(node('issue-confirm-dialog').open,true);assert.match(node('issue-confirm-summary').textContent,/SYNTHETIC-6/);assert.equal(requests.length,0,'opening confirmation must not transmit');
 choose('other');assert.equal(node('issue-safety').hidden,false);assert.equal(form.elements.contact_attempted.required,false);
 node('issue-photo-input').files=[{type:'text/html',size:100}];node('issue-photo-input').handlers.change();assert.match(node('issue-error').textContent,/JPEG/);assert.equal(requests.length,0);
 node('issue-photo-input').files=[{type:'image/png',size:2000001}];node('issue-photo-input').handlers.change();assert.match(node('issue-error').textContent,/2 MB/);
 node('issue-photo-input').files=[{type:'image/png',size:1000}];node('issue-photo-input').handlers.change();assert.equal(node('issue-photo-preview').hidden,false);assert.equal(requests.length,0,'preview must not upload');node('issue-remove-photo').click();assert.equal(node('issue-photo-preview').hidden,true);
 form.elements.notes.value='Still a useful synthetic report.';submit();node('issue-confirm-send').click();await flush();assert.equal(redirect,null);assert.match(node('issue-transfer').textContent,/Not confirmed/);assert.equal(form.elements.notes.value,'Still a useful synthetic report.');assert.equal(node('issue-submit').disabled,false);
 const first=requests.at(-1).request_id;submit();node('issue-confirm-send').click();await flush();assert.equal(requests.at(-1).request_id,first,'same draft retry uses same idempotency key');
 mode='html';submit();node('issue-confirm-send').click();await flush();assert.match(node('issue-error').textContent,/sign-in/);assert.equal(redirect,null);
 mode='reject';submit();node('issue-confirm-send').click();await flush();assert.match(node('issue-error').textContent,/Delivery changed/);assert.equal(redirect,null);
 form.handlers.input();mode='delay';submit();node('issue-confirm-send').click();const count=requests.length;node('issue-confirm-send').click();assert.equal(requests.length,count,'double click sends once');assert.equal(node('issue-submit').disabled,true);release();await flush();assert.equal(redirect,root.dataset.endpoint);assert.notEqual(requests.at(-1).request_id,first);
 win.offline();assert.match(node('issue-transfer').textContent,/awaiting network/);
 const source=fs.readFileSync('assets/js/driver-delivery-issue.js','utf8');assert.doesNotMatch(source,/localStorage|sessionStorage|console\./);assert.match(source,/AbortController/);
 console.log('PASS issue client: validation, safe contact exceptions, confirmation, no premature photo upload, size/type checks, draft retention, retry key, duplicate click, HTML/session error, stale error, confirmed success, timeout guard, offline text and no persistent PII.');
})().catch(e=>{console.error(e);process.exitCode=1;});
