/* APK-only: serve cached card/cosmetic/SFX from Cache Storage. Do not intercept API. */
const CACHE_NAME = 'lltcg-apk-assets-v1';
const MODE_URL = './apk-asset-mode.json';

function isAssetRequest(url) {
  try {
    const u = new URL(url, self.location.href);
    if (u.origin !== self.location.origin) return false;
    const path = u.pathname;
    if (/cardimg\.php$/i.test(path)) return true;
    return /\/assets\/(sleeves|playmats|stamps|sfx)\//i.test(path);
  } catch (e) {
    return false;
  }
}

async function readMode() {
  try {
    const cache = await caches.open(CACHE_NAME);
    const hit = await cache.match(MODE_URL);
    if (!hit) return '';
    const d = await hit.json();
    return d && typeof d.mode === 'string' ? d.mode : '';
  } catch (e) {
    return '';
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  if (!isAssetRequest(event.request.url)) return;
  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    const hit = await cache.match(event.request);
    if (hit) return hit;
    const res = await fetch(event.request);
    const mode = await readMode();
    if (res && res.ok && (mode === 'on_demand' || mode === 'full')) {
      try {
        await cache.put(event.request, res.clone());
      } catch (e) { /* quota */ }
    }
    return res;
  })());
});
