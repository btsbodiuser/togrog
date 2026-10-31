// Төгрөг service worker — app shell cache-first, navigation network-first (4s timeout), Google Fonts stale-while-revalidate.
// BUILD is a hash of index.html + manifest, written by tools/build.py, so every change gets a fresh cache.
const BUILD='d0183dba';
const VERSION='togrog-v1.3.0-'+BUILD;
const SHELL=['./','./index.html','./manifest.webmanifest','./icon-192.png','./icon-512.png','./icon-maskable.png','./apple-touch-icon.png'];
self.addEventListener('install',e=>{e.waitUntil(caches.open(VERSION).then(c=>c.addAll(SHELL)).then(()=>self.skipWaiting()))});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k!==VERSION&&k!==VERSION+'-fonts').map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});
self.addEventListener('fetch',e=>{
  const req=e.request; if(req.method!=='GET')return;
  const url=new URL(req.url);
  if(url.host==='fonts.googleapis.com'||url.host==='fonts.gstatic.com'){
    e.respondWith(caches.open(VERSION+'-fonts').then(async c=>{const hit=await c.match(req);const net=fetch(req).then(r=>{c.put(req,r.clone());return r}).catch(()=>hit);return hit||net}));return;
  }
  if(url.origin!==location.origin||url.pathname.includes('/api/'))return;  // sync API is never cached
  if(req.mode==='navigate'){
    // On weak mobile networks a fetch can hang for a long time: show the cached app after 4s, keep updating in the background
    e.respondWith((async()=>{
      const c=await caches.open(VERSION),hit=await c.match('./index.html');
      const net=fetch(req).then(r=>{if(r.ok)c.put('./index.html',r.clone());return r});
      if(!hit)return net;
      e.waitUntil(net.catch(()=>{}));
      return Promise.race([net.catch(()=>hit),new Promise(r=>setTimeout(()=>r(hit),4000))]);
    })());return;
  }
  e.respondWith(caches.match(req).then(hit=>hit||fetch(req).then(r=>{if(r.ok){const cp=r.clone();caches.open(VERSION).then(c=>c.put(req,cp))}return r})));
});
