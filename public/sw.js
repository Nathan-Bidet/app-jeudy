const SW_VERSION = 'jeudy-pwa-v1';
const SHELL_CACHE = `${SW_VERSION}-shell`;
const STATIC_CACHE = `${SW_VERSION}-static`;
const OFFLINE_URL = '/offline.html';
const SHELL_FILES = [
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/pwa-192.png',
  '/pwa-512.png',
  '/favicon.ico'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) => cache.addAll(SHELL_FILES))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => ![SHELL_CACHE, STATIC_CACHE].includes(key))
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

function isSensitivePath(pathname) {
  return pathname.startsWith('/notifications')
    || pathname.startsWith('/activities/hours/export')
    || pathname.startsWith('/calendar/leaves/export')
    || pathname.startsWith('/two-factor')
    || pathname.startsWith('/login')
    || pathname.startsWith('/logout')
    || pathname.startsWith('/admin');
}

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) {
    return;
  }

  if (isSensitivePath(url.pathname)) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(async () => {
        const cache = await caches.open(SHELL_CACHE);
        return cache.match(OFFLINE_URL);
      })
    );
    return;
  }

  const isStaticAsset = url.pathname.startsWith('/build/')
    || url.pathname.endsWith('.js')
    || url.pathname.endsWith('.css')
    || url.pathname.endsWith('.png')
    || url.pathname.endsWith('.jpg')
    || url.pathname.endsWith('.jpeg')
    || url.pathname.endsWith('.webp')
    || url.pathname.endsWith('.svg')
    || url.pathname.endsWith('.ico')
    || url.pathname.endsWith('.woff2');

  if (!isStaticAsset) {
    return;
  }

  event.respondWith(
    caches.open(STATIC_CACHE).then(async (cache) => {
      const cached = await cache.match(request);
      if (cached) {
        return cached;
      }

      const response = await fetch(request);
      if (response && response.ok) {
        cache.put(request, response.clone());
      }
      return response;
    })
  );
});
