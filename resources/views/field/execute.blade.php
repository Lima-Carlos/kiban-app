@php
    $order = $this->record();
    $payload = $this->payload();
    $directions = \App\Modules\Field\Filament\MyOrdersPage::directionsUrl($order);
    $box = 'border-radius:12px;padding:12px 14px;border:1px solid rgba(127,127,127,.25);background:var(--color-white, #fff)';
@endphp

<x-filament-panels::page>
    {{-- Customer, address and how to get there. --}}
    <x-filament::section>
        <div style="font-weight:700;font-size:17px">{{ $order->customer->displayName() }}</div>
        <div style="font-size:14px;opacity:.8">{{ $order->serviceType->name }}</div>
        @if ($order->site)
            <div style="font-size:14px;margin-top:6px">{{ $order->site->address() }}</div>
            @if ($order->site->reference)
                <div style="font-size:13px;opacity:.75">{{ $order->site->reference }}</div>
            @endif
        @endif
        @if ($order->reported)
            <div style="font-size:14px;margin-top:8px;white-space:pre-line"><strong>{{ __('field.fields.reported') }}:</strong> {{ $order->reported }}</div>
        @endif
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
            @if ($directions)
                <x-filament::button :href="$directions" tag="a" target="_blank" color="gray" icon="heroicon-o-map" size="sm">{{ __('field.my_orders.directions') }}</x-filament::button>
            @endif
            @if ($order->customer->phone)
                <x-filament::button :href="'https://wa.me/'.$order->customer->phone" tag="a" target="_blank" color="gray" icon="heroicon-o-chat-bubble-left-right" size="sm">{{ $this->customerPhone() }}</x-filament::button>
            @endif
        </div>
    </x-filament::section>

    <script src="{{ asset('js/field-queue.js') }}?v=2"></script>
    <div
        wire:ignore
        x-data="{
            order: @js($payload),
            texts: @js(__('field.execute')),
            owner: @js(app(\App\Tenancy\TenantContext::class)->get()->getKey().':'.auth()->id()),
            url: @js(route('field.sync', ['order' => $order->getKey()])),
            clockUrl: @js(route('timeclock.clock')),
            token: @js(csrf_token()),
            pending: [],
            sending: false,
            online: navigator.onLine,
            signIn: false,
            problem: null,
            km: '',
            vehicle: @js($payload['vehicles'][0]['id'] ?? null),
            locating: false,
            material: { id: '', description: '', quantity: 1 },
            note: @js($payload['workDone']),
            signer: @js($payload['signedBy']),
            signed: @js($payload['signedBy'] !== null),
            drawing: false,
            pauseReason: '',
            showPause: false,
            position: null,
            eta: null,
            showLate: false,
            lateReason: '',
            lateSent: null,
            lateNextSent: null,
            showHandoff: false,
            handoffReason: '',
            leaveNow: false,
            push: 'unsupported',
            init() {
                this.watchLeave();
                this.checkPush();
                window.addEventListener('online', () => { this.online = true; this.flush(); });
                window.addEventListener('offline', () => { this.online = false; });
                document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { this.flush(); } });
                setInterval(() => this.flush(), 30000);
                KibanFieldQueue.clock(this.clockUrl).then((clock) => { if (clock?.token) { this.token = clock.token; } });
                this.load().then(() => this.flush());
            },
            // What is still waiting on the phone is shown as already done (it goes by itself).
            async load() {
                try { this.pending = await KibanFieldQueue.pending(this.owner, this.order.id); } catch (e) { this.pending = []; }
                for (const item of this.pending) { this.applyLocally(item.piece); }
            },
            applyLocally(piece) {
                const data = piece.data || {};
                if (piece.type === 'check_in') { this.order.checkedIn = true; this.order.status = 'in_progress'; }
                if (piece.type === 'answer') { this.order.answers[data.key] = data.value; }
                if (piece.type === 'photo') { const key = data.key || '_free'; this.order.photos[key] = (this.order.photos[key] || 0) + 1; }
                if (piece.type === 'material') { this.order.materials.push({ id: piece.id, description: data.description, quantity: data.quantity, unit: data.unit }); }
                if (piece.type === 'material_removed') { this.order.materials = this.order.materials.filter((m) => m.id !== data.piece); }
                if (piece.type === 'signature') { this.signed = true; this.signer = data.name; }
                if (piece.type === 'pause') { this.order.status = 'paused'; }
                if (piece.type === 'finish') { this.order.status = 'done'; this.order.closed = true; }
                if (piece.type === 'on_the_way') { this.order.onTheWay = true; }
                if (piece.type === 'delay') { this.order.delayMinutes = data.minutes; }
                if (piece.type === 'handoff') { this.order.status = 'open'; this.order.closed = true; }
            },
            // A piece of another order (e.g. late for the next service): it goes in the same queue.
            async queueFor(order, url, type, data = {}) {
                const item = await KibanFieldQueue.add(this.owner, order, url, type, data);
                this.pending.push(item);
                this.flush();
            },
            async queue(type, data = {}) {
                const item = await KibanFieldQueue.add(this.owner, this.order.id, this.url, type, data);
                this.applyLocally(item.piece);
                this.pending.push(item);
                this.flush();
            },
            async flush() {
                if (this.sending || this.pending.length === 0) { return; }
                this.sending = true;
                try {
                    for (const item of [...this.pending]) {
                        let answer = await KibanFieldQueue.send(item, this.token);
                        if (answer.result === 'expired') {
                            const clock = await KibanFieldQueue.clock(this.clockUrl);
                            if (clock?.token) { this.token = clock.token; answer = await KibanFieldQueue.send(item, this.token); }
                        }
                        if (answer.result === 'offline') { this.online = false; break; }
                        if (answer.result === 'signin' || answer.result === 'expired') { this.signIn = true; break; }
                        this.online = true;
                        this.signIn = false;
                        await KibanFieldQueue.remove(item.id);
                        this.pending = this.pending.filter((p) => p.id !== item.id);
                        if (answer.result === 'ok') {
                            if (item.order !== this.order.id) { continue; }
                            this.order.missing = answer.data.missing;
                            this.order.status = answer.data.order_status;
                            this.order.closed = ['done', 'canceled', 'open'].includes(answer.data.order_status);
                        } else {
                            // A rule said no (e.g. something required missing to finish): show it and undo locally.
                            this.problem = answer.data?.message ?? this.texts.invalid;
                            if (item.order !== this.order.id) { continue; }
                            if (item.piece.type === 'finish') { this.order.status = 'in_progress'; this.order.closed = false; }
                            if (item.piece.type === 'handoff') { this.order.status = this.order.checkedIn ? 'in_progress' : 'scheduled'; this.order.closed = false; }
                        }
                    }
                } finally {
                    this.sending = false;
                }
            },
            checkIn() {
                this.locating = true;
                const send = (coords) => {
                    this.locating = false;
                    if (coords) { this.position = coords; }
                    this.queue('check_in', {
                        latitude: coords?.latitude ?? null, longitude: coords?.longitude ?? null, accuracy: coords?.accuracy ?? null,
                        km: this.km === '' ? null : parseInt(this.km, 10), vehicle_id: this.km === '' ? null : this.vehicle,
                    });
                };
                if (!navigator.geolocation) { send(null); return; }
                navigator.geolocation.getCurrentPosition((p) => send(p.coords), () => send(null), { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
            },
            answer(key, value) {
                if (this.order.answers[key] === value) { return; }
                this.queue('answer', { key, value });
            },
            async photo(event, key = null) {
                const file = event.target.files?.[0];
                event.target.value = '';
                if (!file) { return; }
                // Stamped on the picture: date and time (server-corrected clock), the place and the order.
                const coords = await this.locate(4000);
                const image = await KibanFieldQueue.shrink(file, 1280, [
                    new Date(KibanFieldQueue.now()).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }),
                    coords ? coords.latitude.toFixed(5) + ', ' + coords.longitude.toFixed(5) + ' (±' + Math.round(coords.accuracy) + ' m)' : null,
                    this.order.stamp,
                ]);
                this.queue('photo', { key, image });
            },
            addMaterial() {
                const chosen = this.order.catalog.find((m) => String(m.id) === String(this.material.id));
                const quantity = parseFloat(String(this.material.quantity).replace(',', '.'));
                if ((!chosen && !this.material.description) || !(quantity > 0)) { return; }
                this.queue('material', { material_id: chosen?.id ?? null, description: chosen?.name ?? this.material.description, unit: chosen?.unit ?? 'un', quantity });
                this.material = { id: '', description: '', quantity: 1 };
            },
            removeMaterial(id) { this.queue('material_removed', { piece: id }); },
            saveNote() { if ((this.note || '') !== (this.order.workDone || '')) { this.order.workDone = this.note; this.queue('note', { text: this.note }); } },
            // Signature pad: the finger draws on the canvas.
            startDraw(e) { const c = this.$refs.pad.getContext('2d'); const r = this.$refs.pad.getBoundingClientRect(); this.drawing = true; c.lineWidth = 2.5; c.lineCap = 'round'; c.strokeStyle = '#111'; c.beginPath(); c.moveTo(e.clientX - r.left, e.clientY - r.top); },
            draw(e) { if (!this.drawing) { return; } const c = this.$refs.pad.getContext('2d'); const r = this.$refs.pad.getBoundingClientRect(); c.lineTo(e.clientX - r.left, e.clientY - r.top); c.stroke(); this.$refs.pad.dataset.used = '1'; },
            endDraw() { this.drawing = false; },
            clearPad() { const pad = this.$refs.pad; pad.getContext('2d').clearRect(0, 0, pad.width, pad.height); delete pad.dataset.used; },
            sign() {
                if (!this.signer || !this.$refs.pad.dataset.used) { this.problem = this.texts.signature_help; return; }
                this.problem = null;
                this.queue('signature', { name: this.signer, image: this.$refs.pad.toDataURL('image/png') });
            },
            pause() { if (!this.pauseReason) { return; } this.queue('pause', { reason: this.pauseReason }); this.showPause = false; },
            localMissing() {
                const missing = this.order.checklist.filter((item) => item.required && !(item.type === 'photo'
                    ? (this.order.photos[item.key] || 0) > 0
                    : (item.type === 'check' ? ['1', 'true', true].includes(this.order.answers[item.key]) : (item.type === 'yes_no' ? ['yes', 'no'].includes(this.order.answers[item.key]) : String(this.order.answers[item.key] ?? '').trim() !== '')))).map((item) => item.label);
                if (this.order.photoRequired && Object.values(this.order.photos).reduce((a, b) => a + b, 0) === 0) { missing.push(this.texts.photo_required); }
                if (this.order.signatureRequired && !this.signed) { missing.push(this.texts.signature_required); }
                return missing;
            },
            locate(timeout = 15000) {
                return new Promise((resolve) => {
                    if (!navigator.geolocation) { resolve(this.position); return; }
                    navigator.geolocation.getCurrentPosition((p) => { this.position = p.coords; resolve(p.coords); }, () => resolve(this.position), { enableHighAccuracy: true, timeout, maximumAge: 120000 });
                });
            },
            clockText(time) { return new Date(time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); },
            // Same estimate as the server (DayPlanner): straight line × road factor ÷ speed, rounded up to 5 min.
            travelMinutes(from, to) {
                const rad = (d) => d * Math.PI / 180;
                const a = Math.sin(rad(to.lat - from.latitude) / 2) ** 2 + Math.cos(rad(from.latitude)) * Math.cos(rad(to.lat)) * Math.sin(rad(to.lng - from.longitude) / 2) ** 2;
                const km = 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)) * this.order.travel.factor;
                return Math.max(5, Math.ceil(km / this.order.travel.speed * 60 / 5) * 5);
            },
            async onTheWay() {
                this.queue('on_the_way');
                const coords = this.order.site ? await this.locate(8000) : null;
                this.eta = coords ? this.clockText(KibanFieldQueue.now() + this.travelMinutes(coords, this.order.site) * 60000) : null;
            },
            whatsappOnTheWay() {
                const text = this.order.onTheWayMessage + (this.eta ? this.texts.arrive_at.replace(':time', this.eta) : '');
                return 'https://wa.me/' + this.order.customerPhone + '?text=' + encodeURIComponent(text);
            },
            // The delay told to the customer of this service or of the next one, with the new time of arrival.
            whatsappLate(target, minutes) {
                const at = target.scheduledAt ? this.clockText(target.scheduledAt + minutes * 60000) : '';
                const text = target.lateMessage.replace(':minutes', minutes).replace(':time', at);
                return 'https://wa.me/' + target.customerPhone + '?text=' + encodeURIComponent(text);
            },
            late(minutes) {
                const data = { minutes, reason: this.lateReason || null };
                if (this.order.checkedIn && this.order.next) {
                    this.queueFor(this.order.next.id, this.order.next.sync, 'delay', data);
                    this.lateNextSent = minutes;
                } else {
                    this.queue('delay', data);
                    this.lateSent = minutes;
                }
                this.showLate = false;
                this.lateReason = '';
            },
            handoff() {
                if (!this.handoffReason.trim()) { return; }
                this.queue('handoff', { reason: this.handoffReason.trim() });
                this.showHandoff = false;
            },
            // While the screen is open: the warning to leave for the next service (the server also sends it to the phone).
            watchLeave() {
                const check = () => {
                    if (!this.order.next || this.order.closed || !this.order.checkedIn) { return; }
                    if (KibanFieldQueue.now() < Date.parse(this.order.next.alertAt) || this.leaveNow) { return; }
                    this.leaveNow = true;
                    if ('vibrate' in navigator) { navigator.vibrate([200, 100, 200]); }
                };
                check();
                setInterval(check, 20000);
            },
            async registration() {
                if (!this.order.pushKey || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { return null; }
                return (await navigator.serviceWorker.getRegistration()) || null;
            },
            async checkPush() {
                const registration = await this.registration();
                if (!registration) { this.push = 'unsupported'; return; }
                if (Notification.permission === 'denied') { this.push = 'blocked'; return; }
                const subscription = await registration.pushManager.getSubscription();
                this.push = subscription && Notification.permission === 'granted' ? 'on' : 'off';
                // The same phone may be used in another company: the server keeps it for this one too.
                if (this.push === 'on' && this.online) { this.savePush(subscription); }
            },
            async enablePush() {
                const registration = await this.registration();
                if (!registration) { return; }
                if (await Notification.requestPermission() !== 'granted') { this.push = 'blocked'; return; }
                const key = this.order.pushKey.replace(/-/g, '+').replace(/_/g, '/');
                const bytes = Uint8Array.from(atob(key + '='.repeat((4 - key.length % 4) % 4)), (c) => c.charCodeAt(0));
                const subscription = (await registration.pushManager.getSubscription()) || await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes });
                if (await this.savePush(subscription)) { this.push = 'on'; }
            },
            async savePush(subscription) {
                try {
                    const response = await fetch(this.order.pushUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.token, 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify(subscription.toJSON()),
                    });
                    return response.ok;
                } catch (e) {
                    return false;
                }
            },
            finish() {
                const missing = this.localMissing();
                if (missing.length) { this.problem = this.texts.missing.replace(':items', missing.join(', ')); return; }
                this.problem = null;
                this.queue('finish');
            },
        }"
        style="display:flex;flex-direction:column;gap:14px"
    >
        {{-- Proof of sending: what is still on the phone. --}}
        <div x-show="!online" x-cloak style="font-size:13px;padding:10px 12px;border-radius:10px;background:rgba(245,158,11,.15);color:#92400e" x-text="texts.offline"></div>
        <div data-field="pending" style="font-size:13px;display:flex;align-items:center;gap:6px">
            <span x-show="pending.length > 0" style="color:#b45309">⏳ <span x-text="texts.pending.replace(':count', pending.length)"></span></span>
            <span x-show="pending.length === 0" style="color:#15803d">✓ <span x-text="texts.all_sent"></span></span>
        </div>
        <div x-show="problem" x-cloak style="font-size:14px;padding:10px 12px;border-radius:10px;background:rgba(220,38,38,.1);color:#991b1b" x-text="problem"></div>
        <div x-show="signIn" x-cloak style="font-size:13px;padding:10px 12px;border-radius:10px;background:rgba(220,38,38,.1);color:#991b1b" x-text="texts.sign_in"></div>

        {{-- Done. --}}
        <div x-show="order.status === 'done'" x-cloak style="{{ $box }};text-align:center;color:#166534">
            <div style="font-size:30px">✓</div>
            <strong x-text="texts.done"></strong>
            <div style="margin-top:12px">
                <x-filament::button tag="a" x-bind:href="order.reportWhatsapp" target="_blank" icon="heroicon-o-chat-bubble-left-right" color="success">
                    {{ __('field.report.send_whatsapp') }}
                </x-filament::button>
                <p style="font-size:12px;opacity:.75;margin:6px 0 0" x-show="pending.length > 0" x-text="texts.report_after_sync"></p>
            </div>
        </div>

        {{-- Passed to another technician: the order is back with the company. --}}
        <div x-show="order.status === 'open'" x-cloak style="{{ $box }};text-align:center;color:#92400e">
            <div style="font-size:26px">↩</div>
            <strong x-text="texts.handed_off"></strong>
        </div>

        {{-- Alerts on this phone (the time to leave for the next service, also with the app closed). --}}
        <div x-show="!order.closed && (push === 'off' || push === 'blocked')" x-cloak style="{{ $box }};display:flex;flex-direction:column;gap:6px">
            <template x-if="push === 'off'">
                <div>
                    <x-filament::button color="gray" icon="heroicon-o-bell-alert" size="sm" x-on:click="enablePush()"><span x-text="texts.alerts_on"></span></x-filament::button>
                    <p style="font-size:12px;opacity:.75;margin:6px 0 0" x-text="texts.alerts_help"></p>
                </div>
            </template>
            <p x-show="push === 'blocked'" style="font-size:13px;color:#b45309;margin:0" x-text="texts.alerts_blocked"></p>
        </div>

        {{-- Before arriving: on the way (message to the customer), late, or pass to another technician. --}}
        <div x-show="!order.closed && !order.checkedIn" style="{{ $box }};display:flex;flex-direction:column;gap:8px">
            <strong style="font-size:16px" x-text="texts.trip"></strong>
            <template x-if="!order.onTheWay">
                <x-filament::button color="primary" icon="heroicon-o-truck" x-on:click="onTheWay()" style="width:100%"><span x-text="texts.on_the_way"></span></x-filament::button>
            </template>
            <template x-if="order.onTheWay && order.customerPhone">
                <div>
                    <p style="font-size:13px;margin:0 0 6px;color:#15803d" x-text="'✓ ' + texts.on_the_way_done"></p>
                    <x-filament::button tag="a" x-bind:href="whatsappOnTheWay()" target="_blank" color="success" icon="heroicon-o-chat-bubble-left-right" style="width:100%"><span x-text="texts.tell_customer"></span></x-filament::button>
                </div>
            </template>
            <p x-show="order.onTheWay && !order.customerPhone" x-cloak style="font-size:13px;margin:0;color:#15803d" x-text="'✓ ' + texts.on_the_way"></p>

            <p x-show="order.delayMinutes" x-cloak style="font-size:13px;margin:0;color:#b45309" x-text="texts.late_sent.replace(':minutes', order.delayMinutes)"></p>
            <template x-if="(lateSent || order.delayMinutes) && order.customerPhone && !order.checkedIn">
                <x-filament::button tag="a" x-bind:href="whatsappLate(order, lateSent || order.delayMinutes)" target="_blank" color="success" outlined icon="heroicon-o-chat-bubble-left-right" style="width:100%"><span x-text="texts.tell_customer_late"></span></x-filament::button>
            </template>
            <x-filament::button color="warning" outlined icon="heroicon-o-clock" x-on:click="showLate = !showLate; showHandoff = false"><span x-text="texts.late"></span></x-filament::button>
            <x-filament::button color="gray" outlined icon="heroicon-o-arrows-right-left" x-on:click="showHandoff = !showHandoff; showLate = false"><span x-text="texts.handoff"></span></x-filament::button>
        </div>

        {{-- "I will be late": 15, 30 or 60 minutes, with an optional reason (for this service or the next one). --}}
        <div x-show="showLate && !order.closed" x-cloak style="{{ $box }}">
            <p style="font-size:13px;opacity:.75;margin:0 0 8px" x-text="texts.late_help"></p>
            <input type="text" x-model="lateReason" :placeholder="texts.late_reason" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:15px;margin-bottom:8px">
            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px">
                <template x-for="minutes in @js(\App\Modules\Field\Services\OrderExecutionService::DELAYS)" :key="minutes">
                    <button type="button" x-on:click="late(minutes)" x-text="texts.late_minutes.replace(':minutes', minutes)"
                        style="padding:12px;border-radius:8px;border:1px solid #f59e0b;background:rgba(245,158,11,.12);color:#92400e;font-weight:700"></button>
                </template>
            </div>
        </div>

        {{-- "Pass to another technician": the reason, then the order goes back to the company. --}}
        <div x-show="showHandoff && !order.closed" x-cloak style="{{ $box }}">
            <p style="font-size:13px;opacity:.75;margin:0 0 8px" x-text="texts.handoff_help"></p>
            <textarea rows="2" x-model="handoffReason" :placeholder="texts.handoff_reason" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:15px"></textarea>
            <x-filament::button color="danger" size="sm" x-on:click="handoff()" x-bind:disabled="!handoffReason.trim()" style="margin-top:8px"><span x-text="texts.handoff_confirm"></span></x-filament::button>
        </div>

        {{-- 1. Arrival. --}}
        <div x-show="!order.closed && !order.checkedIn" style="{{ $box }}">
            <strong style="font-size:16px" x-text="texts.arrival"></strong>
            <p style="font-size:13px;opacity:.75;margin:4px 0 10px" x-text="order.needsPoint ? texts.arrival_point_help : texts.arrival_help"></p>
            <template x-if="order.vehicles.length">
                <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px;margin-bottom:10px">
                    <select x-model="vehicle" style="width:100%;min-width:0;border:1px solid #d1d5db;border-radius:8px;padding:8px">
                        <template x-for="v in order.vehicles" :key="v.id"><option :value="v.id" x-text="v.name"></option></template>
                    </select>
                    <input type="number" inputmode="numeric" x-model="km" :placeholder="texts.km" style="width:100%;min-width:0;border:1px solid #d1d5db;border-radius:8px;padding:8px">
                </div>
            </template>
            <x-filament::button size="xl" icon="heroicon-o-map-pin" x-on:click="checkIn()" x-bind:disabled="locating" style="width:100%">
                <span x-text="locating ? texts.locating : texts.arrived"></span>
            </x-filament::button>
        </div>

        {{-- The next service of the day: until when to finish here, and the warning when it is time to leave. --}}
        <template x-if="order.next && order.checkedIn && !order.closed">
            <div :style="'{{ $box }};' + (leaveNow ? 'border-color:#f59e0b;background:rgba(245,158,11,.12)' : '')">
                <div x-show="leaveNow" style="font-weight:700;color:#92400e;margin-bottom:6px" x-text="'⏰ ' + texts.time_to_leave"></div>
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;opacity:.7" x-text="texts.next_title"></div>
                <a :href="order.next.url" style="display:block;font-weight:700;font-size:15px;margin-top:2px" x-text="texts.next_line.replace(':time', order.next.time).replace(':customer', order.next.customer)"></a>
                <div style="font-size:13px;margin-top:4px">
                    <span x-show="order.next.travel" x-text="texts.next_travel.replace(':minutes', order.next.travel) + ' '"></span>
                    <strong x-text="texts.finish_by.replace(':time', order.next.finishBy)"></strong>
                </div>
                <p x-show="lateNextSent" style="font-size:13px;margin:6px 0 0;color:#b45309" x-text="texts.late_sent.replace(':minutes', lateNextSent)"></p>
                <template x-if="lateNextSent && order.next.customerPhone">
                    <x-filament::button tag="a" x-bind:href="whatsappLate(order.next, lateNextSent)" target="_blank" color="success" outlined size="sm" icon="heroicon-o-chat-bubble-left-right" style="margin-top:8px"><span x-text="texts.tell_customer_late"></span></x-filament::button>
                </template>
                <x-filament::button color="warning" outlined size="sm" icon="heroicon-o-clock" x-on:click="showLate = !showLate" style="margin-top:8px"><span x-text="texts.late_next"></span></x-filament::button>
            </div>
        </template>

        {{-- 2. Checklist. --}}
        <div x-show="order.checkedIn || order.closed" style="{{ $box }}">
            <strong style="font-size:16px" x-text="texts.checklist"></strong>
            <template x-for="item in order.checklist" :key="item.key">
                <div style="padding:10px 0;border-bottom:1px solid rgba(127,127,127,.15)">
                    <div style="font-size:14px;font-weight:600;margin-bottom:6px"><span x-text="item.label"></span><span x-show="item.required" style="color:#dc2626"> *</span></div>
                    <template x-if="item.type === 'check'">
                        <label style="display:flex;gap:8px;align-items:center;font-size:15px">
                            <input type="checkbox" style="width:22px;height:22px" :checked="['1','true',true].includes(order.answers[item.key])" :disabled="order.closed" x-on:change="answer(item.key, $event.target.checked ? '1' : '0')">
                            <span x-text="texts.done_item"></span>
                        </label>
                    </template>
                    <template x-if="item.type === 'yes_no'">
                        <div style="display:flex;gap:8px">
                            <template x-for="option in ['yes','no']">
                                <button type="button" :disabled="order.closed" x-on:click="answer(item.key, option)" x-text="texts[option]"
                                    :style="'flex:1;padding:10px;border-radius:8px;border:1px solid #d1d5db;font-weight:600;' + (order.answers[item.key] === option ? 'background:#044299;color:#fff;border-color:#044299' : 'background:#fff;color:#39383A')"></button>
                            </template>
                        </div>
                    </template>
                    <template x-if="item.type === 'number'">
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="text" inputmode="decimal" :value="order.answers[item.key] ?? ''" :disabled="order.closed" x-on:change="answer(item.key, $event.target.value.replace(',', '.'))" style="flex:1;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:15px">
                            <span x-text="item.unit || ''" style="opacity:.7"></span>
                        </div>
                    </template>
                    <template x-if="item.type === 'text'">
                        <textarea rows="2" :disabled="order.closed" x-on:change="answer(item.key, $event.target.value)" x-text="order.answers[item.key] ?? ''" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:15px"></textarea>
                    </template>
                    <template x-if="item.type === 'choice'">
                        <select :disabled="order.closed" x-on:change="answer(item.key, $event.target.value)" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:15px">
                            <option value="" x-text="texts.choose"></option>
                            <template x-for="option in item.options"><option :value="option" x-text="option" :selected="order.answers[item.key] === option"></option></template>
                        </select>
                    </template>
                    <template x-if="item.type === 'photo'">
                        <label style="display:inline-flex;gap:8px;align-items:center;padding:10px 14px;border-radius:8px;border:1px solid #d1d5db;cursor:pointer">
                            <input type="file" accept="image/*" capture="environment" style="display:none" :disabled="order.closed" x-on:change="photo($event, item.key)">
                            📷 <span x-text="(order.photos[item.key] || 0) > 0 ? texts.photos_taken.replace(':count', order.photos[item.key]) : texts.take_photo"></span>
                        </label>
                    </template>
                </div>
            </template>

            {{-- Free photos. --}}
            <div style="padding-top:10px">
                <label style="display:inline-flex;gap:8px;align-items:center;padding:10px 14px;border-radius:8px;border:1px solid #d1d5db;cursor:pointer" x-show="!order.closed">
                    <input type="file" accept="image/*" capture="environment" style="display:none" x-on:change="photo($event)">
                    📷 <span x-text="texts.more_photos"></span>
                </label>
                <span style="font-size:13px;opacity:.7;margin-left:6px" x-show="(order.photos['_free'] || 0) > 0" x-text="texts.photos_taken.replace(':count', order.photos['_free'] || 0)"></span>
            </div>
        </div>

        {{-- 3. Materials and what was done. --}}
        <div x-show="order.checkedIn || order.closed" style="{{ $box }}">
            <strong style="font-size:16px" x-text="texts.materials"></strong>
            <template x-for="m in order.materials" :key="m.id">
                <div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;font-size:14px">
                    <span><strong x-text="m.quantity"></strong> <span x-text="m.unit"></span> · <span x-text="m.description"></span></span>
                    <button type="button" x-show="!order.closed" x-on:click="removeMaterial(m.id)" style="color:#dc2626" x-text="texts.remove"></button>
                </div>
            </template>
            <div x-show="!order.closed" style="display:grid;grid-template-columns:1fr 80px auto;gap:8px;margin-top:8px">
                <select x-model="material.id" style="border:1px solid #d1d5db;border-radius:8px;padding:8px">
                    <option value="" x-text="texts.choose_material"></option>
                    <template x-for="m in order.catalog" :key="m.id"><option :value="m.id" x-text="m.name"></option></template>
                </select>
                <input type="text" inputmode="decimal" x-model="material.quantity" style="border:1px solid #d1d5db;border-radius:8px;padding:8px">
                <x-filament::button color="gray" x-on:click="addMaterial()" icon="heroicon-o-plus"></x-filament::button>
            </div>
            <div style="margin-top:12px">
                <div style="font-size:14px;font-weight:600;margin-bottom:6px" x-text="texts.work_done"></div>
                <textarea rows="3" x-model="note" x-on:change="saveNote()" :disabled="order.closed" :placeholder="texts.work_done_help" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:15px"></textarea>
            </div>
        </div>

        {{-- 4. The customer's signature. --}}
        <div x-show="(order.checkedIn || order.closed)" style="{{ $box }}">
            <strong style="font-size:16px" x-text="texts.signature"></strong>
            <template x-if="signed">
                <p style="color:#15803d;margin:8px 0 0">✓ <span x-text="texts.signed_by.replace(':name', signer)"></span></p>
            </template>
            <div x-show="!signed && !order.closed">
                <input type="text" x-model="signer" :placeholder="texts.signer" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:15px;margin:8px 0">
                <canvas x-ref="pad" width="320" height="160" style="width:100%;max-width:480px;height:160px;border:1px dashed #9ca3af;border-radius:8px;touch-action:none;background:#fff"
                    x-on:pointerdown="startDraw($event)" x-on:pointermove="draw($event)" x-on:pointerup="endDraw()" x-on:pointerleave="endDraw()"></canvas>
                <div style="display:flex;gap:8px;margin-top:8px">
                    <x-filament::button color="gray" size="sm" x-on:click="clearPad()"><span x-text="texts.clear"></span></x-filament::button>
                    <x-filament::button size="sm" x-on:click="sign()"><span x-text="texts.save_signature"></span></x-filament::button>
                </div>
            </div>
        </div>

        {{-- 5. Finish or pause. --}}
        <div x-show="order.checkedIn && !order.closed" style="display:flex;flex-direction:column;gap:8px">
            <x-filament::button size="xl" color="success" icon="heroicon-o-check-circle" x-on:click="finish()" style="width:100%"><span x-text="texts.finish"></span></x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-pause" x-on:click="showPause = !showPause" x-show="order.status !== 'paused'"><span x-text="texts.pause"></span></x-filament::button>
            <div x-show="showPause" x-cloak style="{{ $box }}">
                <textarea rows="2" x-model="pauseReason" :placeholder="texts.pause_reason" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:15px"></textarea>
                <x-filament::button color="warning" size="sm" x-on:click="pause()" style="margin-top:8px"><span x-text="texts.pause_confirm"></span></x-filament::button>
            </div>
            <p x-show="order.status === 'paused'" style="font-size:13px;color:#b45309;margin:0" x-text="texts.paused_help"></p>
            <x-filament::button color="gray" icon="heroicon-o-play" x-show="order.status === 'paused'" x-on:click="checkIn()"><span x-text="texts.resume"></span></x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
