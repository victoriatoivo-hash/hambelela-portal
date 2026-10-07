'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),handlers={},cached=[],removed=[];let offline=false,claimed=false;
const scope='https://example.invalid/apps/delivery/driver/';
const context={URL,Promise,self:{registration:{scope},clients:{claim:async()=>{claimed=true;}},addEventListener:(name,fn)=>handlers[name]=fn},caches:{open:async()=>({add:async u=>cached.push(u)}),keys:async()=>['hambelela-driver-offline-v0','hambelela-driver-offline-v1','portal-other-cache'],delete:async k=>removed.push(k),match:async u=>({offline:u})},fetch:async(request,options)=>{assert.equal(options.cache,'no-store');if(offline)throw Error('offline');return {network:request.url};}};
vm.runInNewContext(fs.readFileSync(path.join(root,'apps/delivery/driver/sw.js'),'utf8'),context);
(async()=>{let task;handlers.install({waitUntil:p=>task=p});await task;assert.deepEqual(cached,[scope+'offline.html']);handlers.activate({waitUntil:p=>task=p});await task;assert.deepEqual(removed,['hambelela-driver-offline-v0']);assert.equal(claimed,true);
let response;function dispatch(method,mode,url){response=undefined;handlers.fetch({request:{method,mode,url},respondWith:p=>response=p});return response;}
assert.equal(dispatch('POST','navigate',scope+'api.php'),undefined);assert.equal(dispatch('GET','cors',scope+'api.php'),undefined);assert.equal(dispatch('GET','navigate','https://example.invalid/apps/operations/orders.php'),undefined);
assert.equal((await dispatch('GET','navigate',scope+'index.php')).network,scope+'index.php');offline=true;assert.equal((await dispatch('GET','navigate',scope+'index.php')).offline,scope+'offline.html');
const manifest=JSON.parse(fs.readFileSync(path.join(root,'apps/delivery/driver/manifest.webmanifest'),'utf8'));assert.equal(manifest.scope,'./');assert.equal(manifest.display,'standalone');for(const icon of manifest.icons)assert.ok(fs.existsSync(path.resolve(root,'apps/delivery/driver',icon.src)),icon.src);
console.log('PASS: Driver-only offline fallback; no customer/API/payment cache; no mutation replay; unrelated caches preserved; manifest icons exist.');
})().catch(e=>{console.error(e);process.exitCode=1;});
