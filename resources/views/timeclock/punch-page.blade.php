@php
    use App\Modules\TimeClock\Models\Punch;
    use App\Modules\TimeClock\Services\ScheduleCalendar;
    $plan = $this->todayPlan();
    $today = $this->today();
@endphp

<x-filament-panels::page>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
        <x-filament::section icon="heroicon-o-finger-print">
            <x-slot name="heading">{{ ucfirst($today->translatedFormat(__('timeclock.holidays.day_month_weekday_format'))) }}</x-slot>

            <div
                wire:ignore
                x-data="{
                    stream: null,
                    camera: 'waiting',
                    location: 'waiting',
                    coords: null,
                    busy: false,
                    init() {
                        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 } }, audio: false })
                                .then((stream) => { this.stream = stream; this.$refs.video.srcObject = stream; this.camera = 'ok'; })
                                .catch(() => { this.camera = 'denied'; });
                        } else {
                            this.camera = 'denied';
                        }

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
                <video x-ref="video" autoplay playsinline muted x-show="camera === 'ok'" style="width:100%;max-height:320px;object-fit:cover;border-radius:12px;background:#000"></video>

                <div style="font-size:13px;display:flex;flex-direction:column;gap:4px">
                    <span x-show="camera === 'waiting'">{{ __('timeclock.punches.camera_waiting') }}</span>
                    <span x-show="camera === 'denied'" style="color:#b45309">{{ __('timeclock.punches.camera_denied') }}</span>
                    <span x-show="location === 'waiting'">{{ __('timeclock.punches.location_waiting') }}</span>
                    <span x-show="location === 'ok'" style="color:#15803d">{{ __('timeclock.punches.location_ok') }}</span>
                    <span x-show="location === 'denied'" style="color:#b45309">{{ __('timeclock.punches.location_denied') }}</span>
                </div>

                <x-filament::button size="xl" icon="heroicon-o-finger-print" x-on:click="punch()" x-bind:disabled="busy" style="padding:18px;font-size:18px">
                    {{ __('timeclock.punches.punch_button') }}
                </x-filament::button>
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
