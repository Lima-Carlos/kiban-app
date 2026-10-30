// Kiban app: service worker. It lets phones "install" the app (icon on the home screen) and lets "Clock in"
// open without signal: only that page and the files of the screens (styles, scripts, fonts, images) are kept on
// the phone. Every other page always comes fresh from the server. The page kept on the phone is used only when
// there is no signal; signing out erases it.
const CACHE = 'kiban-v2';
const PUNCH_PAGE = /\/ponto\/bater-ponto\/?$/;
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
            // Signed out: nothing of the person stays on the phone (the punches waiting to be sent stay in their queue).
            event.waitUntil(caches.delete(CACHE));
            return;
        }

        event.respondWith(page(request, url));
        return;
    }

    if (ASSETS.test(url.pathname)) {
        event.respondWith(asset(request, event));
    }
});

/** Pages: always from the server; without signal, "Clock in" from the phone's memory. */
async function page(request, url) {
    try {
        const response = await fetch(request);
        if (PUNCH_PAGE.test(url.pathname) && response.ok && !response.redirected) {
            const cache = await caches.open(CACHE);
            await cache.put(url.pathname, response.clone());
        }
        return response;
    } catch (error) {
        const cache = await caches.open(CACHE);
        const kept = await cache.match(url.pathname);
        if (kept) { return kept; }

        // Any other page without signal: go to "Clock in" when it is kept (e.g. the app opened from the home screen).
        const pages = (await cache.keys()).filter((key) => PUNCH_PAGE.test(new URL(key.url).pathname));
        if (pages.length > 0) { return Response.redirect(pages[0].url, 302); }

        throw error;
    }
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
