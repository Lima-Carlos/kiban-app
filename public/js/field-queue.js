// Kiban Campo: the technician's work without signal, like WhatsApp.
// Every piece of a work order (arrival, answers, photos, materials, signature, finish) first goes to a queue on
// the phone (IndexedDB) and is then sent, in order, one at a time. Without signal it waits and goes by itself
// when the signal comes back. The time of each piece is the phone's clock corrected by the server's clock
// (the same correction as the time clock). Each piece has its own code: sending it twice counts once.
(function () {
    const DB = 'kiban-campo';
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

    /**
     * A photo from the camera, reduced on the phone (at most 1280 px, JPEG) before going to the queue. The lines
     * of "stamp" (date and time, place) are written on the bottom of the picture, like a stamp that cannot be
     * removed later.
     */
    function shrink(file, max = 1280, stamp = []) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => {
                const scale = Math.min(1, max / Math.max(image.width, image.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(image.width * scale);
                canvas.height = Math.round(image.height * scale);
                const context = canvas.getContext('2d');
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                const lines = stamp.filter(Boolean);
                if (lines.length) {
                    const size = Math.max(14, Math.round(canvas.width / 40));
                    const height = lines.length * size * 1.35 + size * 0.6;
                    context.fillStyle = 'rgba(0, 0, 0, 0.55)';
                    context.fillRect(0, canvas.height - height, canvas.width, height);
                    context.fillStyle = '#fff';
                    context.font = `600 ${size}px sans-serif`;
                    context.textBaseline = 'top';
                    lines.forEach((line, index) => context.fillText(String(line), size * 0.6, canvas.height - height + size * 0.3 + index * size * 1.35, canvas.width - size * 1.2));
                }
                URL.revokeObjectURL(image.src);
                resolve(canvas.toDataURL('image/jpeg', 0.72));
            };
            image.onerror = reject;
            image.src = URL.createObjectURL(file);
        });
    }

    window.KibanFieldQueue = {
        uuid,
        shrink,
        now: () => Date.now() + offset(),

        /** Keeps a piece of an order in the queue (it goes as soon as possible). */
        async add(owner, order, url, type, data = {}, id = null) {
            const item = { id: id || uuid(), owner, order, url, created: Date.now(), piece: { id: null, type, taken_at: Date.now() + offset(), data } };
            item.piece.id = item.id;
            await run('readwrite', (store) => store.put(item));
            return item;
        },

        async pending(owner, order = null) {
            const all = await run('readonly', (store) => store.getAll());
            return all.filter((item) => item.owner === owner && (order === null || item.order === order)).sort((a, b) => a.created - b.created);
        },

        remove: (id) => run('readwrite', (store) => store.delete(id)),

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

        /** Sends one piece. Returns {result: 'ok'|'refused'|'offline'|'signin'|'expired'|'invalid', data}. */
        async send(item, token) {
            let response;
            try {
                response = await fetch(item.url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    redirect: 'manual',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ piece: item.piece }),
                });
            } catch (e) {
                return { result: 'offline' };
            }

            if (response.status === 419) { return { result: 'expired' }; }
            if (response.status === 422) { return { result: 'invalid' }; }
            if (!response.ok || !(response.headers.get('content-type') || '').includes('json')) {
                return { result: response.status >= 500 || response.status === 429 ? 'offline' : 'signin' };
            }

            const data = await response.json();
            learnClock(data.now);

            return { result: data.status === 'ok' ? 'ok' : 'refused', data };
        },
    };
})();
