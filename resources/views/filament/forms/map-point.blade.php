@php
    // The latitude / longitude fields sit next to this one in the same form.
    $base = \Illuminate\Support\Str::beforeLast($getStatePath(), '.');
@endphp

<div
    x-data="{
        latitude: $wire.entangle(@js($base.'.'.$latitudeField)),
        longitude: $wire.entangle(@js($base.'.'.$longitudeField)),
        busy: false,
        error: null,
        locate() {
            this.error = null;

            if (! navigator.geolocation) {
                this.error = @js(__('app.map.not_supported'));

                return;
            }

            this.busy = true;
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    this.latitude = position.coords.latitude.toFixed(6);
                    this.longitude = position.coords.longitude.toFixed(6);
                    this.busy = false;
                },
                () => {
                    this.error = @js(__('app.map.denied'));
                    this.busy = false;
                },
                { enableHighAccuracy: true, timeout: 15000 },
            );
        },
        get mapUrl() {
            return `https://www.openstreetmap.org/?mlat=${this.latitude}&mlon=${this.longitude}#map=17/${this.latitude}/${this.longitude}`;
        },
    }"
    style="display:flex;flex-wrap:wrap;align-items:center;gap:12px"
>
    <x-filament::button type="button" color="gray" icon="heroicon-o-map-pin" x-on:click="locate()" x-bind:disabled="busy">
        {{ __('app.map.use_my_location') }}
    </x-filament::button>

    <a x-show="latitude && longitude" x-bind:href="mapUrl" target="_blank" rel="noopener" style="color:#044299;text-decoration:underline;font-size:14px">
        {{ __('app.map.see_on_map') }}
    </a>

    <span x-show="busy" style="font-size:13px;opacity:.7">{{ __('app.map.locating') }}</span>
    <span x-show="error" x-text="error" style="font-size:13px;color:#dc2626"></span>

    <div style="flex-basis:100%;font-size:12px;opacity:.7">{{ __('app.map.help') }}</div>
</div>
