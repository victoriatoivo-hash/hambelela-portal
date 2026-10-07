'use strict';
// Driver-only scope. Cache no customer data, credentials, API responses or mutations.
const CACHE='hambelela-driver-offline-v1';
const OFFLINE=new URL('offline.html',self.registration.scope).href;
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(cache=>cache.add(OFFLINE)));});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('hambelela-driver-offline-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));});
self.addEventListener('fetch',event=>{
 if(event.request.method!=='GET'||event.request.mode!=='navigate')return;
 const url=new URL(event.request.url);
 if(!url.href.startsWith(self.registration.scope))return;
 event.respondWith(fetch(event.request,{cache:'no-store'}).catch(()=>caches.match(OFFLINE)));
});
