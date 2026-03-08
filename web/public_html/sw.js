// 🔧 SIGNula Service Worker
// @see https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API
// @see https://web.dev/service-worker-lifecycle/

const CACHE_VERSION = 'v1';
const STATIC_CACHE = `signula-static-${CACHE_VERSION}`;
const DYNAMIC_CACHE = `signula-dynamic-${CACHE_VERSION}`;

const STATIC_ASSETS = [
    '/',
    '/offline.html',
    '/assets/css/main.css',
    '/assets/js/main.js',
    '/assets/images/favicon.svg',
    '/manifest.json'
];

// Install: pre-cache static assets
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

// Activate: clean old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key !== STATIC_CACHE && key !== DYNAMIC_CACHE)
                    .map(key => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch: routing strategy
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET
    if (request.method !== 'GET') return;

    // API: network only
    if (url.pathname.startsWith('/api/')) return;

    // Navigation: network-first
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(response => {
                    // Cache successful navigation responses
                    const clone = response.clone();
                    caches.open(DYNAMIC_CACHE).then(cache => cache.put(request, clone));
                    return response;
                })
                .catch(() => {
                    return caches.match(request)
                        .then(cached => cached || caches.match('/offline.html'));
                })
        );
        return;
    }

    // Static assets & CDN: cache-first
    if (isStaticAsset(url) || isCDN(url)) {
        event.respondWith(
            caches.match(request).then(cached => {
                if (cached) return cached;
                return fetch(request).then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(STATIC_CACHE).then(cache => cache.put(request, clone));
                    }
                    return response;
                }).catch(() => new Response('', { status: 408 }));
            })
        );
        return;
    }
});

function isStaticAsset(url) {
    return /\.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)$/i.test(url.pathname);
}

function isCDN(url) {
    return ['cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'fonts.googleapis.com', 'fonts.gstatic.com'].includes(url.hostname);
}

// Handle messages
self.addEventListener('message', event => {
    if (event.data === 'skipWaiting') {
        self.skipWaiting();
    }
});
