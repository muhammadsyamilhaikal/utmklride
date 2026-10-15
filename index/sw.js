const CACHE_NAME = 'utmkl-customer-v1';
const STATIC_ASSETS = [
  './style.css',
];

// ── Install: pre-cache static shell ──────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache =>
        Promise.allSettled(
          STATIC_ASSETS.map(asset =>
            cache.add(asset).catch(err => console.warn(`SW: failed to cache ${asset}:`, err))
          )
        )
      )
      .then(() => self.skipWaiting())
  );
});

// ── Activate: remove stale caches ────────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

// ── Fetch: network-first, fallback to cache ───────────────────────────────────
self.addEventListener('fetch', event => {
  const { request } = event;
  if (request.method !== 'GET') return;

  event.respondWith(
    fetch(request)
      .then(response => {
        if (response && response.status === 200 && response.type === 'basic') {
          // Do not cache PHP responses to prevent stale dynamic data
          if (!request.url.includes('.php')) {
            caches.open(CACHE_NAME).then(cache => cache.put(request, response.clone()));
          }
        }
        return response;
      })
      .catch(async () => {
        const cached = await caches.match(request);
        if (cached) return cached;

        if (request.mode === 'navigate') {
          // Show the booking page from cache, or a minimal offline message
          const indexCache = await caches.match('./index.php');
          if (indexCache) return indexCache;
          return new Response(
            `<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UTMKL Ride – Offline</title>
            <style>body{font-family:sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f4ff;color:#1e293b;text-align:center;padding:20px;}
            h1{font-size:1.4rem;margin-bottom:8px;}p{color:#64748b;font-size:14px;}</style></head>
            <body><div style="font-size:48px;margin-bottom:16px;">📶</div>
            <h1>You're Offline</h1><p>Please check your connection and try again.</p></body></html>`,
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          );
        }

        return new Response('', { status: 408, statusText: 'Request Timeout' });
      })
  );
});
