/* سامانه توسعه تجارت — service worker
 * - Static assets and uploaded images: cache-first (they are versioned / immutable).
 * - Page navigations: always network; an offline page only when the network is down.
 * - Nothing private is ever cached: no HTML, no API, no POST, no wallet/letters/admin data.
 */
const VERSION = 'sadt-v4';
const OFFLINE = '/offline.html';
const PRECACHE = [OFFLINE, '/icons/icon-192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
    return;
  }
  if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/media/') || url.pathname.startsWith('/icons/')) {
    event.respondWith(
      caches.open(VERSION).then((cache) =>
        cache.match(req).then((hit) => hit || fetch(req).then((res) => {
          if (res.ok && res.type === 'basic') cache.put(req, res.clone());
          return res;
        }))
      )
    );
  }
});
