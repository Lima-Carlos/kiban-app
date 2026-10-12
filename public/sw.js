// Kiban app: minimal service worker. It lets phones "install" the app (icon on the home screen).
// Nothing is cached: the data of the companies always comes fresh from the server.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
