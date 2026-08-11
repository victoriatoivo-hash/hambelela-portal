const CACHE_VERSION = 'hambelela-owner-shell-v1';
const OFFLINE_ASSETS = ['/pwa-offline.html', '/assets/pwa/hambelela-192.png', '/assets/pwa/hambelela-512.png'];
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE_VERSION).then(cache => cache.addAll(OFFLINE_ASSETS))); self.skipWaiting(); });
self.addEventListener('activate', event => { event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('hambelela-owner-shell-') && key !== CACHE_VERSION).map(key => caches.delete(key)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET' || request.mode !== 'navigate') return;
  event.respondWith(fetch(request, {cache: 'no-store'}).catch(() => caches.match('/pwa-offline.html')));
});
