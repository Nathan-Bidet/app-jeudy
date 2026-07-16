const SW_VERSION = 'jeudy-pwa-v3';
const SHELL_CACHE = `${SW_VERSION}-shell`;
const STATIC_CACHE = `${SW_VERSION}-static`;
const PAGES_CACHE = `${SW_VERSION}-pages`;
const OFFLINE_URL = '/offline.html';
const SHELL_FILES = [
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/pwa-192.png',
  '/pwa-512.png',
  '/pwa-maskable-512.png',
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
          .filter((key) => ![SHELL_CACHE, STATIC_CACHE, PAGES_CACHE].includes(key))
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

function isSensitivePath(pathname) {
  return pathname.startsWith('/notifications')
    || pathname.startsWith('/api')
    || pathname.startsWith('/broadcasting')
    || pathname.startsWith('/activities/hours/export')
    || pathname.startsWith('/calendar/leaves/export')
    || pathname.startsWith('/two-factor')
    || pathname.startsWith('/login')
    || pathname.startsWith('/logout')
    || pathname.startsWith('/admin');
}

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: 'Jeudy', body: event.data ? event.data.text() : '' };
  }

  event.waitUntil(
    self.registration.showNotification(data.title || 'Jeudy', {
      body: data.body || '',
      icon: data.icon || '/pwa-192.png',
      badge: '/pwa-192.png',
      data: {
        url: data.url || '/',
        resourceType: data.resourceType || null,
        resourceId: data.resourceId || null,
        action: data.action || null,
        notificationId: data.notificationId || null,
      },
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const notifData = event.notification.data || {};
  const targetUrl = notifData.url || '/';
  const resourceType = notifData.resourceType;
  const resourceId = notifData.resourceId;
  const notificationId = notifData.notificationId;

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      const existing = windowClients.find(
        (client) => new URL(client.url).origin === self.location.origin
      );
      if (existing) {
        if (resourceType) {
          existing.postMessage({ type: 'OPEN_RESOURCE', resourceType, resourceId, notificationId });
        } else {
          existing.postMessage({ type: 'SW_NAVIGATE', url: targetUrl });
        }
        return existing.focus();
      }
      return clients.openWindow(targetUrl);
    })
  );
});

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
      (async () => {
        try {
          const response = await fetch(request);
          if (response && response.ok) {
            const pagesCache = await caches.open(PAGES_CACHE);
            pagesCache.put(request, response.clone());
          }
          return response;
        } catch (error) {
          const pagesCache = await caches.open(PAGES_CACHE);
          const cachedPage = await pagesCache.match(request);
          if (cachedPage) {
            return cachedPage;
          }
          const shellCache = await caches.open(SHELL_CACHE);
          return shellCache.match(OFFLINE_URL);
        }
      })()
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
