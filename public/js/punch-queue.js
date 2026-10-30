// Kiban Ponto: punches without signal, like WhatsApp.
// Every punch first goes to a queue on the phone (IndexedDB, with the selfie, the location and the time) and is
// then sent to the server. Without signal it waits and goes by itself when the signal comes back (on "online",
// every 30 seconds and when the screen opens). The time is the phone's clock corrected by the server's clock
// (measured on every answer). Each punch has its own code: sending it twice counts once.
(function () {
    const DB = 'kiban-ponto';
    const STORE = 'queue';
    const OFFSET = 'kiban-clock-offset';

    function open() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB, 1);
            request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: 'id' });
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async function run(mode, action) {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, mode);
            const result = action(tx.objectStore(STORE));
            tx.oncomplete = () => resolve(result.result);
            tx.onerror = () => reject(tx.error);
        });
    }

    const queue = {
        put: (item) => run('readwrite', (store) => store.put(item)),
        remove: (id) => run('readwrite', (store) => store.delete(id)),
        all: () => run('readonly', (store) => store.getAll()),
    };

    function uuid() {
        if (crypto.randomUUID) { return crypto.randomUUID(); }
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    function offset() {
        try { return parseInt(localStorage.getItem(OFFSET) || '0', 10) || 0; } catch (e) { return 0; }
    }

    function learnClock(serverNow) {
        if (!serverNow) { return; }
        try { localStorage.setItem(OFFSET, String(serverNow - Date.now())); } catch (e) { /* private mode */ }
    }

    window.KibanPunchQueue = {
        uuid,
        queue,

        /** Server time now, as well as the phone can tell. */
        now: () => Date.now() + offset(),

        /** Asks the server's clock (and a fresh form token). Null without signal or without a sign-in. */
        async clock(url) {
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                if (!response.ok || !(response.headers.get('content-type') || '').includes('json')) { return null; }
                const data = await response.json();
                learnClock(data.now);
                return data;
            } catch (e) {
                return null;
            }
        },

        /**
         * Sends one punch. Returns {result: 'ok'|'refused'|'offline'|'signin'|'expired'|'invalid', data}.
         */
        async send(url, token, item) {
            let response;
            try {
                response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    redirect: 'manual',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        id: item.id,
                        taken_at: item.taken_at,
                        photo: item.photo,
                        latitude: item.latitude,
                        longitude: item.longitude,
                        accuracy: item.accuracy,
                        without_photo: item.without_photo,
                    }),
                });
            } catch (e) {
                return { result: 'offline' };
            }

            if (response.status === 419) { return { result: 'expired' }; }
            if (response.status === 422) { return { result: 'invalid' }; }
            if (!response.ok || !(response.headers.get('content-type') || '').includes('json')) {
                // 0 (redirect), 401, 403: not signed in here any more. 5xx/429: try again later.
                return { result: response.status >= 500 || response.status === 429 ? 'offline' : 'signin' };
            }

            const data = await response.json();
            learnClock(data.now);

            return { result: data.status === 'ok' ? 'ok' : 'refused', data };
        },
    };
})();
