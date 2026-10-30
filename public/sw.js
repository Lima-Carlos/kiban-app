// Kiban app: service worker. It lets phones "install" the app (icon on the home screen) and lets the pages of
// the work in the field open without signal: "Clock in", the technician's "My work orders" and each order (kept
// when opened with signal, or when "My work orders" opens), plus the files of the screens (styles, scripts,
// fonts, images). Every other page always comes fresh from the server. A page kept on the phone is used only
// when there is no signal; signing out erases them.
const CACHE = 'kiban-v3';
// Pages that work without signal: Ponto "Clock in", Campo "My work orders" and one work order.
const OFFLINE_PAGES = /\/(ponto\/bater-ponto|campo\/minhas-os(\/\d+)?)\/?$/;
// Where to go when the app is opened without signal (the technician's list first, then "Clock in").
const START_PAGES = [/\/campo\/minhas-os\/?$/, /\/ponto\/bater-ponto\/?$/];
// Livewire's script is inside the company's address (/<company>/livewire-xxxx/livewire.js).
const ASSETS = /^\/(css|js|fonts|images)\/|\/livewire-[^/]+\/livewire(\.min)?\.js$/;

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil((async () => {
    for (const name of await caches.keys()) {
        if (name !== CACHE) { await caches.delete(name); }
    }
    await self.clients.claim();
})()));

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        if (url.pathname.endsWith('/auth/signed-out')) {
            // Signed out: nothing of the person stays on the phone (the work waiting to be sent stays in its queue).
            event.waitUntil(caches.delete(CACHE));
            return;
        }

        event.respondWith(page(request, url));
        return;
    }

    // "My work orders" asks for each order with signal, so it is kept for later without signal.
    if (request.headers.get('X-Kiban-Keep') === '1' && OFFLINE_PAGES.test(url.pathname)) {
        event.respondWith(keep(request, url));
        return;
    }

    if (ASSETS.test(url.pathname)) {
        event.respondWith(asset(request, event));
    }
});

/** Pages: always from the server; without signal, the kept one. */
async function page(request, url) {
    try {
        const response = await fetch(request);
        if (OFFLINE_PAGES.test(url.pathname) && response.ok && !response.redirected) {
            const cache = await caches.open(CACHE);
            await cache.put(url.pathname, response.clone());
        }
        return response;
    } catch (error) {
        const cache = await caches.open(CACHE);
        const kept = await cache.match(url.pathname);
        if (kept) { return kept; }

        // Any other page without signal (e.g. the app opened from the home screen): go to a kept start page.
        const keys = (await cache.keys()).map((key) => new URL(key.url));
        for (const start of START_PAGES) {
            const found = keys.find((key) => start.test(key.pathname));
            if (found) { return Response.redirect(found.href, 302); }
        }

        throw error;
    }
}

/** A page asked in the background to be kept (never shown now). */
async function keep(request, url) {
    const response = await fetch(new Request(request.url, { credentials: 'same-origin' }));
    if (response.ok && !response.redirected) {
        const cache = await caches.open(CACHE);
        await cache.put(url.pathname, response.clone());
    }
    return response;
}

/** Files of the screens: from the phone's memory at once, refreshed in the background. */
async function asset(request, event) {
    const cache = await caches.open(CACHE);
    const kept = await cache.match(request);
    const fresh = fetch(request).then((response) => {
        if (response.ok) { cache.put(request, response.clone()); }
        return response;
    });

    if (kept) {
        event.waitUntil(fresh.catch(() => {}));
        return kept;
    }

    return fresh;
}
