const CACHE_NAME = 'utmkl-driver-v3';
const STATIC_ASSETS = [
  './dashboard.php',
  './login.php',
  './offline.html',
  './driver.css',
  './css/dark.css',
  './js/db.js',
  './manifest.json',
  './icons/icon-192.png',
  './icons/icon-512.png'
];


self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      // Use Promise.allSettled so that if one file (like a missing icon) fails, 
      // the Service Worker still installs successfully.
      return Promise.allSettled(
        STATIC_ASSETS.map(asset => cache.add(asset).catch(err => console.warn(`Failed to cache ${asset}:`, err)))
      );
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const { request } = event;

  // 1. Only cache GET requests. (POST requests will throw TypeError if put in cache)
  if (request.method !== 'GET') {
    return; // Let the browser handle naturally
  }

  // 2. Bypass cache completely for API calls
  if (request.url.includes('/api/')) {
    return; // Let the browser handle naturally
  }

  // 3. Network First, Fallback to Cache
  event.respondWith(
    fetch(request)
      .then(response => {
        // Only cache valid HTTP 200 responses. Do not cache 404s or 500s.
        if (response && response.status === 200 && response.type === 'basic') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
        }
        return response;
      })
      .catch(async () => {
        // Network failed (offline). Look in the cache.
        const cachedRes = await caches.match(request);
        if (cachedRes) {
          return cachedRes;
        }
        
        // If it's a page navigation request and not in cache, show offline.html
        if (request.mode === 'navigate') {
          return caches.match('./offline.html');
        }
        
        // Let it fail naturally if it's an image/css not in cache
        return new Response('', { status: 408, statusText: 'Request Timeout' });
      })
  );
});
