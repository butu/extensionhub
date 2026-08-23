// Minimal service worker for Extension Hub.
//
// Strategy:
// - install: cache only the app shell ('/'), nothing else.
// - navigations: network-first, falling back to the cached shell when offline.
// - /build/ and /images/: cache-first, populated lazily on first fetch.
// - /data/: never cached, always network, so the extension snapshot stays fresh.
const SHELL_CACHE = 'extensionhub-shell-v1';
const RUNTIME_CACHE = 'extensionhub-runtime-v1';
const KNOWN_CACHES = [SHELL_CACHE, RUNTIME_CACHE];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll(['/']))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys
                .filter((key) => !KNOWN_CACHES.includes(key))
                .map((key) => caches.delete(key))
        ))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Extension/comment snapshots must always be fresh; never persist them.
    if (url.pathname.startsWith('/data/')) {
        event.respondWith(fetch(request));
        return;
    }

    // Page navigations: prefer the network, fall back to the cached shell offline.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/'))
        );
        return;
    }

    // Hashed build assets and images rarely change per-URL: cache-first.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/images/')) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) {
                    return cached;
                }

                return fetch(request).then((response) => {
                    if (response.ok) {
                        const responseClone = response.clone();
                        caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, responseClone));
                    }
                    return response;
                });
            })
        );
    }
});
