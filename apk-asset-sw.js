/* APK-only: cache hits for card/cosmetic/SFX. Misses go to the network as a URL fetch. */
const CACHE_NAME = 'lltcg-apk-assets-v1';

function isAssetRequest(url) {
  try {
    const u = new URL(url, self.location.href);
    if (u.origin !== self.location.origin) return false;
    const path = u.pathname;
    // Never intercept match API or shop catalogs (api.php, *_catalog.json).
    if (/\/api\.php$/i.test(path)) return false;
    if (/_catalog\.json$/i.test(path)) return false;
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

function isAudioRequest(url, request) {
  if (request && (request.destination === 'audio' || request.destination === 'video')) return true;
  try {
    const path = new URL(url, self.location.href).pathname;
    return /\.(wav|mp3|ogg|m4a|aac)$/i.test(path);
  } catch (e) {
    return false;
  }
}

function guessAudioType(url) {
  const path = String(url).split('?')[0].toLowerCase();
  if (path.endsWith('.mp3')) return 'audio/mpeg';
  if (path.endsWith('.m4a') || path.endsWith('.aac')) return 'audio/mp4';
  if (path.endsWith('.ogg')) return 'audio/ogg';
  return 'audio/wav';
}

function parseByteRange(header, size) {
  const m = /^bytes=(\d*)-(\d*)$/i.exec(String(header || '').trim());
  if (!m) return null;
  let start = m[1] === '' ? NaN : parseInt(m[1], 10);
  let end = m[2] === '' ? NaN : parseInt(m[2], 10);
  if (Number.isNaN(start) && Number.isNaN(end)) return null;
  if (Number.isNaN(start)) {
    start = Math.max(0, size - end);
    end = size - 1;
  } else if (Number.isNaN(end)) {
    end = size - 1;
  }
  if (start < 0 || end >= size || start > end) return null;
  return { start, end };
}

async function audioFromCache(hit, url, rangeHeader) {
  const buf = await hit.arrayBuffer();
  const size = buf.byteLength;
  const type = hit.headers.get('Content-Type') || guessAudioType(url);
  const range = parseByteRange(rangeHeader, size);
  if (!range) {
    return new Response(buf, {
      status: 200,
      headers: {
        'Content-Type': type,
        'Content-Length': String(size),
        'Accept-Ranges': 'bytes',
      },
    });
  }
  const slice = buf.slice(range.start, range.end + 1);
  return new Response(slice, {
    status: 206,
    headers: {
      'Content-Type': type,
      'Content-Length': String(slice.byteLength),
      'Content-Range': 'bytes ' + range.start + '-' + range.end + '/' + size,
      'Accept-Ranges': 'bytes',
    },
  });
}

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', () => {
  /* Avoid claiming clients: WebView SW network replay breaks Shop/catalog fetches. */
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  if (!isAssetRequest(event.request.url)) return;
  const url = cacheKey(event.request.url);
  const audio = isAudioRequest(url, event.request);
  event.respondWith((async () => {
    try {
      const cache = await caches.open(CACHE_NAME);
      const hit = await cache.match(url);
      if (hit && hit.ok) {
        if (audio) return audioFromCache(hit, url, event.request.headers.get('Range'));
        return hit;
      }
    } catch (e) { /* network */ }
    if (audio && event.request.headers.get('Range')) {
      try {
        return await fetch(event.request);
      } catch (e2) { /* fall through */ }
    }
    return fetch(url, { credentials: 'same-origin', redirect: 'follow' });
  })());
});
