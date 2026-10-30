@php
    use App\Modules\TimeClock\Models\Punch;
    use App\Modules\TimeClock\Services\ScheduleCalendar;
    $plan = $this->todayPlan();
    $today = $this->today();
    $needsNotice = $this->needsPhotoNotice();
    $withoutPhoto = $this->allowsWithoutPhoto();
@endphp

<x-filament-panels::page>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
        <x-filament::section icon="heroicon-o-finger-print">
            <x-slot name="heading">{{ ucfirst($today->translatedFormat(__('timeclock.holidays.day_month_weekday_format'))) }}</x-slot>

            <script src="{{ asset('js/punch-queue.js') }}?v=1"></script>
            <div
                wire:ignore
                x-data="{
                    stream: null,
                    notice: @js($needsNotice),
                    camera: 'waiting',
                    location: 'waiting',
                    coords: null,
                    busy: false,
                    // The queue on the phone (punches kept without signal), only of this person in this company.
                    owner: @js($this->queueOwner()),
                    sendUrl: @js(route('timeclock.queue')),
                    clockUrl: @js(route('timeclock.clock')),
                    token: @js(csrf_token()),
                    renderedAt: @js(now()->getTimestampMs()),
                    timezone: @js($today->timezoneName),
                    texts: @js(__('timeclock.queue')),
                    pending: [],
                    online: navigator.onLine,
                    sending: false,
                    signIn: false,
                    message: null,
                    receipt: null,
                    problems: [],
                    init() {
                        // The camera opens only after the privacy notice of the photos was acknowledged.
                        if (!this.notice) { this.startCamera(); }

                        if (navigator.geolocation) {
                            navigator.geolocation.getCurrentPosition(
                                (position) => { this.coords = position.coords; this.location = 'ok'; },
                                () => { this.location = 'denied'; },
                                { enableHighAccuracy: true, timeout: 20000, maximumAge: 60000 },
                            );
                        } else {
                            this.location = 'denied';
                        }

                        // Sends what is waiting when the signal comes back, when the screen opens and every 30 seconds.
                        window.addEventListener('online', () => { this.online = true; this.flush(); });
                        window.addEventListener('offline', () => { this.online = false; });
                        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { this.flush(); } });
                        setInterval(() => this.flush(), 30000);
                        KibanPunchQueue.clock(this.clockUrl).then((clock) => { if (clock?.token) { this.token = clock.token; } });
                        this.load().then(() => this.flush());
                    },
                    time(ms) {
                        return new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: this.timezone }).format(new Date(ms));
                    },
                    text(key, values = {}) {
                        return Object.entries(values).reduce((text, [name, value]) => text.replace(':' + name, value), this.texts[key]);
                    },
                    async load() {
                        try {
                            this.pending = (await KibanPunchQueue.queue.all()).filter((item) => item.owner === this.owner).sort((a, b) => a.taken_at - b.taken_at);
                        } catch (e) {
                            this.pending = [];
                        }
                    },
                    startCamera() {
                        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 } }, audio: false })
                                .then((stream) => { this.stream = stream; this.$refs.video.srcObject = stream; this.camera = 'ok'; })
                                .catch(() => { this.camera = 'denied'; });
                        } else {
                            this.camera = 'denied';
                        }
                    },
                    async acknowledge() {
                        this.busy = true;
                        try {
                            await $wire.acknowledgePhotoNotice();
                            this.notice = false;
                            this.startCamera();
                        } finally {
                            this.busy = false;
                        }
                    },
                    selfie() {
                        const video = this.$refs.video;
                        if (this.camera !== 'ok' || !video.videoWidth) { return null; }
                        const canvas = document.createElement('canvas');
                        const width = Math.min(640, video.videoWidth);
                        canvas.width = width;
                        canvas.height = Math.round(video.videoHeight * width / video.videoWidth);
                        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                        return canvas.toDataURL('image/jpeg', 0.8);
                    },
                    // The punch is kept on the phone first, then sent: without signal it waits there.
                    async punch(withoutPhoto = false) {
                        if (this.busy) { return; }
                        this.busy = true;
                        try {
                            const item = {
                                id: KibanPunchQueue.uuid(),
                                owner: this.owner,
                                taken_at: KibanPunchQueue.now(),
                                photo: withoutPhoto ? null : this.selfie(),
                                latitude: this.coords?.latitude ?? null,
                                longitude: this.coords?.longitude ?? null,
                                accuracy: this.coords?.accuracy ?? null,
                                without_photo: withoutPhoto,
                            };
                            this.message = null;
                            this.receipt = null;
                            this.problems = [];
                            await KibanPunchQueue.queue.put(item);
                            await this.load();
                            await this.flush();
                            if (this.pending.some((pending) => pending.id === item.id)) {
                                this.message = this.text('saved', { time: this.time(item.taken_at) });
                            }
                        } finally {
                            this.busy = false;
                        }
                    },
                    async flush() {
                        if (this.sending || this.pending.length === 0) { return; }
                        this.sending = true;
                        let sent = false;
                        try {
                            for (const item of [...this.pending]) {
                                let answer = await KibanPunchQueue.send(this.sendUrl, this.token, item);
                                if (answer.result === 'expired') {
                                    const clock = await KibanPunchQueue.clock(this.clockUrl);
                                    if (clock?.token) { this.token = clock.token; answer = await KibanPunchQueue.send(this.sendUrl, this.token, item); }
                                }
                                if (answer.result === 'offline') { this.online = false; break; }
                                if (answer.result === 'signin' || answer.result === 'expired') { this.signIn = true; break; }
                                this.online = true;
                                this.signIn = false;
                                await KibanPunchQueue.queue.remove(item.id);
                                if (answer.result === 'ok') {
                                    sent = true;
                                    this.message = this.text('done', { time: answer.data.time, nsr: answer.data.nsr });
                                    this.receipt = answer.data.receipt;
                                } else {
                                    this.problems.push(this.text('refused', { time: this.time(item.taken_at), reason: answer.data?.message ?? this.texts.invalid }));
                                }
                            }
                        } finally {
                            await this.load();
                            this.sending = false;
                        }
                        if (sent) {
                            // A page opened from the phone's memory is old: open it fresh; otherwise only the lists.
                            if (KibanPunchQueue.now() - this.renderedAt > 600000) { window.location.reload(); } else { $wire.$refresh(); }
                        }
                    },
                }"
                style="display:flex;flex-direction:column;gap:12px;align-items:stretch"
            >
                <div x-show="!online" x-cloak data-queue="offline" style="font-size:13px;padding:10px 12px;border-radius:10px;background:rgba(245,158,11,.15);color:#92400e">
                    <span x-text="texts.offline"></span>
                </div>

                <div x-show="notice" style="display:flex;flex-direction:column;gap:10px">
                    <strong style="font-size:16px">{{ __('timeclock.privacy.title') }}</strong>
                    @foreach (app(\App\Modules\TimeClock\Services\PhotoNoticeService::class)->paragraphs() as $paragraph)
                        <p style="font-size:14px;margin:0">{{ $paragraph }}</p>
                    @endforeach
                    <x-filament::button icon="heroicon-o-check" x-on:click="acknowledge()" x-bind:disabled="busy">
                        {{ __('timeclock.privacy.acknowledge') }}
                    </x-filament::button>
                </div>

                <video x-ref="video" autoplay playsinline muted x-show="!notice && camera === 'ok'" style="width:100%;max-height:320px;object-fit:cover;border-radius:12px;background:#000"></video>

                <div x-show="!notice" style="font-size:13px;display:flex;flex-direction:column;gap:4px">
                    <span x-show="camera === 'waiting'">{{ __('timeclock.punches.camera_waiting') }}</span>
                    <span x-show="camera === 'denied'" style="color:#b45309">{{ __('timeclock.punches.camera_denied') }}</span>
                    <span x-show="location === 'waiting'">{{ __('timeclock.punches.location_waiting') }}</span>
                    <span x-show="location === 'ok'" style="color:#15803d">{{ __('timeclock.punches.location_ok') }}</span>
                    <span x-show="location === 'denied'" style="color:#b45309">{{ __('timeclock.punches.location_denied') }}</span>
                </div>

                <div x-show="!notice">
                    <x-filament::button size="xl" icon="heroicon-o-finger-print" x-on:click="punch()" x-bind:disabled="busy" style="padding:18px;font-size:18px;width:100%">
                        {{ __('timeclock.punches.punch_button') }}
                    </x-filament::button>
                </div>

                @if ($withoutPhoto)
                    <div style="display:flex;flex-direction:column;gap:4px;align-items:center">
                        <x-filament::button color="gray" size="sm" icon="heroicon-o-no-symbol" x-on:click="punch(true)" x-bind:disabled="busy">
                            {{ __('timeclock.privacy.without_photo') }}
                        </x-filament::button>
                        <span style="font-size:12px;opacity:.7;text-align:center">{{ __('timeclock.privacy.without_photo_help') }}</span>
                    </div>
                @endif

                {{-- What happened to the last punch: sent (with the receipt) or kept on the phone. --}}
                <div x-show="message" x-cloak data-queue="message" x-bind:style="'font-size:14px;padding:10px 12px;border-radius:10px;' + (receipt ? 'background:rgba(22,163,74,.12);color:#166534' : 'background:rgba(245,158,11,.15);color:#92400e')">
                    <span x-text="message"></span>
                    <a x-show="receipt" x-bind:href="receipt" target="_blank" style="color:#044299;margin-left:6px;white-space:nowrap">{{ __('timeclock.receipt.open') }}</a>
                </div>

                <template x-for="problem in problems">
                    <div style="font-size:13px;padding:10px 12px;border-radius:10px;background:rgba(220,38,38,.1);color:#991b1b" x-text="problem"></div>
                </template>

                <div x-show="signIn" x-cloak style="font-size:13px;padding:10px 12px;border-radius:10px;background:rgba(220,38,38,.1);color:#991b1b">
                    <span x-text="texts.sign_in"></span>
                </div>

                {{-- Proof of sending: each punch kept on the phone shows "waiting for signal" until it goes. --}}
                <div x-show="pending.length > 0" x-cloak data-queue="pending" style="border:1px dashed rgba(127,127,127,.5);border-radius:10px;padding:8px 12px">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:4px">
                        <strong style="font-size:14px"><span x-text="texts.pending"></span> (<span x-text="pending.length"></span>)</strong>
                        <x-filament::button color="gray" size="xs" icon="heroicon-o-arrow-path" x-on:click="flush()" x-bind:disabled="sending">
                            {{ __('timeclock.queue.send_now') }}
                        </x-filament::button>
                    </div>
                    <template x-for="item in pending" x-bind:key="item.id">
                        <div style="display:flex;justify-content:space-between;gap:8px;padding:4px 0">
                            <strong style="font-size:16px" x-text="time(item.taken_at)"></strong>
                            <span style="font-size:13px;color:#b45309" x-text="sending ? '⏳ ' + texts.sending : '⏳ ' + texts.waiting"></span>
                        </div>
                    </template>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-clock">
            <x-slot name="heading">{{ __('timeclock.punches.today') }}</x-slot>
            <p style="margin-bottom:10px">
                @if ($plan['holiday'])
                    <x-filament::badge color="warning">{{ __('timeclock.employees.holiday') }}: {{ $plan['holiday'] }}</x-filament::badge>
                @elseif ($plan['plan'])
                    {{ __('timeclock.punches.expected', ['start' => $plan['plan']['start'], 'end' => $plan['plan']['end'], 'hours' => ScheduleCalendar::hours($plan['plan']['minutes'])]) }}
                @else
                    {{ __('timeclock.employees.day_off') }}
                @endif
            </p>
            @forelse ($this->todayPunches() as $punch)
                <div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.2)">
                    <span style="white-space:nowrap">
                        <strong style="font-size:18px">{{ $punch->localTime()->format('H:i') }}</strong>
                        <span style="font-size:12px;color:#15803d;margin-left:4px;white-space:nowrap">✓ {{ __('timeclock.queue.sent') }}</span>
                    </span>
                    <span style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                        @foreach ((array) $punch->flags as $flag)
                            <x-filament::badge color="warning" size="sm">{{ __('timeclock.punches.flags.'.$flag) }}</x-filament::badge>
                        @endforeach
                        <a href="{{ $this->receiptUrl($punch) }}" target="_blank" style="color:#044299;font-size:13px">{{ __('timeclock.receipt.short') }}</a>
                    </span>
                </div>
            @empty
                <p style="opacity:.7">{{ __('timeclock.punches.none_today') }}</p>
            @endforelse
        </x-filament::section>
    </div>

    <x-filament::section icon="heroicon-o-document-text" collapsible collapsed>
        <x-slot name="heading">{{ __('timeclock.punches.recent') }}</x-slot>
        @forelse ($this->recentPunches() as $punch)
            <div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.15);font-size:14px">
                <span>{{ ucfirst($punch->localTime()->translatedFormat(__('timeclock.holidays.day_month_weekday_format'))) }} · <strong>{{ $punch->localTime()->format('H:i') }}</strong></span>
                <a href="{{ $this->receiptUrl($punch) }}" target="_blank" style="color:#044299">{{ __('timeclock.receipt.short') }} #{{ $punch->nsr }}</a>
            </div>
        @empty
            <p style="opacity:.7">{{ __('timeclock.punches.none') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
