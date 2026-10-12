import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const source=fs.readFileSync('assets/js/marketing-workspace.js','utf8');
const start=source.indexOf(' const uploadFile=');const end=source.indexOf(" form.addEventListener('submit'",start);
let scenario='success',sent,rendered=0;
class Data{constructor(){this.values={};}append(k,v){this.values[k]=v;}}
class Request{constructor(){this.upload={};}open(method,path){assert.equal(method,'POST');assert.equal(path,'media.php');}setRequestHeader(k,v){assert.equal(k,'Accept');assert.equal(v,'application/json');}send(data){sent=data.values;this.upload.onprogress({lengthComputable:true,loaded:40,total:100});if(scenario==='offline'){this.onerror();return;}this.status=scenario==='success'?200:422;this.responseText=JSON.stringify(scenario==='success'?{ok:true,id:1}:{ok:false,message:'File format rejected'});this.onload();}}
const context=vm.createContext({FormData:Data,XMLHttpRequest:Request,form:{elements:{csrf:{value:'test-token'}}},renderQueue:()=>rendered++});
vm.runInContext(source.slice(start,end)+'\nthis.runUpload=uploadFile;',context);
const job={file:{name:'sample.png'},state:'queued',percent:0};await context.runUpload(job,'12');assert.equal(sent.csrf,'test-token');assert.equal(sent.item_id,'12');assert.equal(sent.asset,job.file);assert.equal(job.state,'uploaded');assert.equal(job.percent,100);assert.ok(rendered>=3);
scenario='invalid';const bad={file:{name:'fake.jpg'}};await context.runUpload(bad,'0');assert.equal(bad.state,'error');assert.equal(bad.message,'File format rejected');
scenario='offline';const interrupted={file:{name:'sample.wav'}};await context.runUpload(interrupted,'0');assert.equal(interrupted.state,'uncertain');assert.match(interrupted.message,/Check the library/);
console.log('PASS upload progress, CSRF and assignment forwarding, server rejection and uncertain-result duplicate prevention');
