const GARBALIA_SW_VERSION = 'garbalia-pos-v10';
const STATIC_CACHE = GARBALIA_SW_VERSION + '-static';

self.addEventListener('install', function () {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        return key.indexOf('garbalia-pos-') === 0 && key !== STATIC_CACHE ? caches.delete(key) : Promise.resolve();
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

function offlinePage() {
  return new Response(
    '<!doctype html><html lang="ka"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GARBALIA POS</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6efe4;color:#2b1b10;font-family:Arial,sans-serif;text-align:center}main{width:min(460px,calc(100% - 32px));padding:30px;border:1px solid #e4d2bc;border-radius:24px;background:#fffaf2;box-shadow:0 20px 50px rgba(43,27,16,.16)}h1{margin:0 0 10px;font-size:1.45rem}p{margin:0;color:#6d5140;font-weight:700;line-height:1.5}</style></head><body><main><h1>ინტერნეტთან კავშირი არ არის</h1><p>შეკვეთების უსაფრთხოდ დასამუშავებლად GARBALIA POS-ს აქტიური ინტერნეტი სჭირდება. კავშირის აღდგენის შემდეგ გვერდი განაახლე.</p></main></body></html>',
    {status: 503, headers: {'Content-Type': 'text/html; charset=UTF-8', 'Cache-Control': 'no-store'}}
  );
}

function isStaticAsset(url) {
  return url.pathname.indexOf('/assets/') === 0 ||
    url.pathname === '/Logo.png' ||
    url.pathname === '/manifest.webmanifest' ||
    /\.(?:css|js|png|jpg|jpeg|svg|webp|ico|woff2?)$/i.test(url.pathname);
}

function isCodeAsset(url) {
  return /\.(?:css|js)$/i.test(url.pathname);
}

self.addEventListener('fetch', function (event) {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;

  // Dynamic POS pages must always come from the server so order/table state is
  // current. Never cache HTML navigations.
  if (event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request, {cache: 'no-store'}).catch(offlinePage));
    return;
  }

  if (!isStaticAsset(url)) return;

  // All application CSS/JS URLs are versioned (?v=...). Serve a cached version
  // immediately for zero-wait UI startup, then refresh that exact URL in the
  // background. A new deployment changes the URL/version, so stale code cannot
  // mask a new build while repeat page loads avoid unnecessary network waits.
  if (isCodeAsset(url)) {
    event.respondWith(
      caches.open(STATIC_CACHE).then(function (cache) {
        return cache.match(event.request).then(function (cached) {
          const refresh = fetch(event.request, {cache: 'no-cache'}).then(function (response) {
            if (response && response.ok) cache.put(event.request, response.clone());
            return response;
          }).catch(function () { return null; });

          if (cached) {
            event.waitUntil(refresh);
            return cached;
          }

          return refresh.then(function (response) {
            return response || new Response('', {status: 503});
          });
        });
      })
    );
    return;
  }

  event.respondWith(
    caches.open(STATIC_CACHE).then(function (cache) {
      return cache.match(event.request).then(function (cached) {
        if (cached) return cached;
        return fetch(event.request).then(function (response) {
          if (response && response.ok) cache.put(event.request, response.clone());
          return response;
        });
      });
    })
  );
});
