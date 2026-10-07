const APP_BASE = new URL(self.registration.scope);
const CACHE_PREFIX = `sheet-academy-assets-${encodeURIComponent(APP_BASE.pathname)}-`;
const CACHE_NAME = `${CACHE_PREFIX}v3`;
const OFFLINE_URL = new URL('offline.html', APP_BASE).href;
const STATIC_ASSETS = [
    'manifest.json',
    'images/icon.svg',
    'images/icon-192.png',
    'images/icon-512.png',
    'images/icon-maskable-512.png',
    'js/vendor/Sortable.min.js',
].map((path) => new URL(path, APP_BASE).href);

const isAllowedAsset = (value) => {
    try {
        const url = new URL(value, APP_BASE);
        if (url.origin !== APP_BASE.origin || url.search || url.hash) {
            return false;
        }

        if (STATIC_ASSETS.includes(url.href)) {
            return true;
        }

        const buildPath = new URL('build/assets/', APP_BASE).pathname;
        return url.pathname.startsWith(buildPath)
            && /^[^/]+\.(?:css|js|woff2?|ttf|otf)$/.test(url.pathname.slice(buildPath.length));
    } catch {
        return false;
    }
};

const isStaticResponse = (response) => response.ok
    && !response.redirected
    && response.type !== 'opaque'
    && /^(?:text\/css|(?:text|application)\/(?:javascript|x-javascript)|application\/(?:json|manifest\+json|font-woff|vnd\.ms-fontobject|octet-stream)|font\/[^;]+|image\/(?:png|svg\+xml))(?:;|$)/i.test(response.headers.get('content-type') || '');

const cacheStaticAsset = async (url) => {
    if (!isAllowedAsset(url)) {
        return;
    }

    const response = await fetch(url, { credentials: 'omit', cache: 'reload' });
    if (isStaticResponse(response)) {
        const cache = await caches.open(CACHE_NAME);
        await cache.put(url, response);
    }
};

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const response = await fetch(OFFLINE_URL, { credentials: 'omit', cache: 'reload' });
        if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('text/html')) {
            throw new Error('The static offline page is unavailable.');
        }

        const cache = await caches.open(CACHE_NAME);
        await cache.put(OFFLINE_URL, response);
        await Promise.all(STATIC_ASSETS.map(cacheStaticAsset));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys
            .filter((key) => key === 'dental-ui-v1' || (key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME))
            .map((key) => caches.delete(key)));
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data?.type !== 'CACHE_STATIC_ASSETS' || !Array.isArray(event.data.urls)) {
        return;
    }

    const sourceUrl = event.source?.url ? new URL(event.source.url) : null;
    if (!sourceUrl || sourceUrl.origin !== APP_BASE.origin || !sourceUrl.pathname.startsWith(APP_BASE.pathname)) {
        return;
    }

    event.waitUntil(Promise.allSettled(event.data.urls.slice(0, 40)
        .filter((url) => typeof url === 'string' && isAllowedAsset(url))
        .map(cacheStaticAsset)));
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== APP_BASE.origin || request.headers.has('range')) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(async () => {
            const cache = await caches.open(CACHE_NAME);
            return await cache.match(OFFLINE_URL) || new Response('أنت غير متصل بالإنترنت.', {
                status: 503,
                headers: { 'Content-Type': 'text/plain; charset=utf-8' },
            });
        }));
        return;
    }

    if (!isAllowedAsset(request.url)) {
        return;
    }

    event.respondWith((async () => {
        const cache = await caches.open(CACHE_NAME);
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }

        const response = await fetch(request);
        if (isStaticResponse(response)) {
            await cache.put(request, response.clone());
        }
        return response;
    })());
});
