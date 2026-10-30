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

    <script src="{{ asset('js/field-queue.js') }}?v=1"></script>
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
            init() {
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
                            this.order.missing = answer.data.missing;
                            this.order.status = answer.data.order_status;
                            this.order.closed = ['done', 'canceled'].includes(answer.data.order_status);
                        } else {
                            // A rule said no (e.g. something required missing to finish): show it and undo locally.
                            this.problem = answer.data?.message ?? this.texts.invalid;
                            if (item.piece.type === 'finish') { this.order.status = 'in_progress'; this.order.closed = false; }
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
                const image = await KibanFieldQueue.shrink(file);
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
        </div>

        {{-- 1. Arrival. --}}
        <div x-show="!order.closed && !order.checkedIn" style="{{ $box }}">
            <strong style="font-size:16px" x-text="texts.arrival"></strong>
            <p style="font-size:13px;opacity:.75;margin:4px 0 10px" x-text="order.needsPoint ? texts.arrival_point_help : texts.arrival_help"></p>
            <template x-if="order.vehicles.length">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
                    <select x-model="vehicle" style="border:1px solid #d1d5db;border-radius:8px;padding:8px">
                        <template x-for="v in order.vehicles" :key="v.id"><option :value="v.id" x-text="v.name"></option></template>
                    </select>
                    <input type="number" inputmode="numeric" x-model="km" :placeholder="texts.km" style="border:1px solid #d1d5db;border-radius:8px;padding:8px">
                </div>
            </template>
            <x-filament::button size="xl" icon="heroicon-o-map-pin" x-on:click="checkIn()" x-bind:disabled="locating" style="width:100%">
                <span x-text="locating ? texts.locating : texts.arrived"></span>
            </x-filament::button>
        </div>

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
