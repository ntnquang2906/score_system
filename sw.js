const CACHE_NAME = "score-system-shell-v1";

const STATIC_ASSETS = [
    "style.css",
    "script.js",
    "criteria.json",
    "manifest.json",
    "icons/icon-192.png",
    "icons/icon-512.png",
    "icons/icon-180.png"
];

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

self.addEventListener("fetch", (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== "GET" || url.origin !== self.location.origin) {
        return;
    }

    const isStaticAsset = STATIC_ASSETS.some((asset) => url.pathname.endsWith("/" + asset));

    if (!isStaticAsset) {
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => {
            const network = fetch(event.request)
                .then((response) => {
                    if (response.ok) {
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, response.clone()));
                    }
                    return response;
                })
                .catch(() => cached);

            return cached || network;
        })
    );
});
