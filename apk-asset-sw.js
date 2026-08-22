/* APK-only: cache hits for card/cosmetic images. Audio is not intercepted. */
const CACHE_NAME = 'lltcg-apk-assets-v1';

function isAssetRequest(url) {
  try {
    const u = new URL(url, self.location.href);
    if (u.origin !== self.location.origin) return false;
    const path = u.pathname;
    if (/\/api\.php$/i.test(path)) return false; // never intercept api.php
    if (/_catalog\.json$/i.test(path)) return false;
    if (/\.(wav|mp3|ogg|m4a|aac)$/i.test(path)) return false;
    if (/cardimg\.php$/i.test(path)) return true;
    if (/\/(playmat|lltcg-back)\.png$/i.test(path)) return true;
    return /\/assets\/(sleeves|playmats|stamps|sfx)\//i.test(path);
  } catch (e) {
    return false;
  }
}

function cacheKey(url) {
  try {
    const u = new URL(url, self.location.href);
    u.hash = '';
    return u.href;
  } catch (e) {
    return url;
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
  const url = cacheKey(event.request.url);
  event.respondWith((async () => {
    try {
      const cache = await caches.open(CACHE_NAME);
      const hit = await cache.match(url, { ignoreVary: true });
      if (hit && hit.ok) return hit;
    } catch (e) { /* network */ }
    return fetch(url, { credentials: 'same-origin', redirect: 'follow' });
  })());
});
