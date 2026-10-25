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

            <div
                wire:ignore
                x-data="{
                    stream: null,
                    notice: @js($needsNotice),
                    camera: 'waiting',
                    location: 'waiting',
                    coords: null,
                    busy: false,
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
                    async punchWithoutPhoto() {
                        this.busy = true;
                        try {
                            await $wire.punch(null, this.coords?.latitude ?? null, this.coords?.longitude ?? null, this.coords?.accuracy ?? null, true);
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
                    async punch() {
                        this.busy = true;
                        try {
                            await $wire.punch(this.selfie(), this.coords?.latitude ?? null, this.coords?.longitude ?? null, this.coords?.accuracy ?? null);
                        } finally {
                            this.busy = false;
                        }
                    },
                }"
                style="display:flex;flex-direction:column;gap:12px;align-items:stretch"
            >
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
                        <x-filament::button color="gray" size="sm" icon="heroicon-o-no-symbol" x-on:click="punchWithoutPhoto()" x-bind:disabled="busy">
                            {{ __('timeclock.privacy.without_photo') }}
                        </x-filament::button>
                        <span style="font-size:12px;opacity:.7;text-align:center">{{ __('timeclock.privacy.without_photo_help') }}</span>
                    </div>
                @endif
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
                    <strong style="font-size:18px">{{ $punch->localTime()->format('H:i') }}</strong>
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
