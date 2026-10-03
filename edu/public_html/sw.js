/* Arad Edu — service worker (PWA)
 * - Static assets (CSS/JS/fonts/icons): cache-first, refreshed in background.
 * - Pages: always from the network (they are personal and permission-dependent,
 *   so they are never cached); when offline, a static offline page is shown.
 * - Files, uploads, API and any non-GET request: untouched.
 */
'use strict';

var VERSION = 'aradedu-v1.0.2';
var STATIC = VERSION + '-static';
var OFFLINE_URL = 'offline.html';
var PRECACHE = [
  OFFLINE_URL,
  'assets/img/icons/icon-192.png',
  'assets/img/icons/icon-96.png',
  'manifest.webmanifest'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(STATIC).then(function (c) { return c.addAll(PRECACHE); }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k.indexOf('aradedu-') === 0 && k !== STATIC; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

function isStatic(url) {
  return url.origin === self.location.origin && /\/assets\/(css|js|fonts|img)\//.test(url.pathname);
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Page navigations: network only, offline fallback
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(function () {
        return caches.open(STATIC).then(function (c) { return c.match(OFFLINE_URL); });
      })
    );
    return;
  }

  // Static assets: cache first, update in background (stale-while-revalidate)
  if (isStatic(url)) {
    event.respondWith(
      caches.open(STATIC).then(function (c) {
        return c.match(req).then(function (hit) {
          var net = fetch(req).then(function (res) {
            if (res && res.ok && res.type === 'basic') c.put(req, res.clone());
            return res;
          }).catch(function () { return hit; });
          return hit || net;
        });
      })
    );
  }
  // Everything else (files, uploads, API, JSON endpoints): default browser behaviour
});
