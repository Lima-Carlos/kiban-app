<x-layout :title="__('fleet.station.title')">
    <h1>{{ __('fleet.station.title') }}</h1>
    <p>{{ $tenant->name }}</p>

    @if ($checked)
        @if ($fuelUp)
            <div style="background:#dcfce7;color:#166534;border-radius:10px;padding:14px 16px;margin:16px 0;text-align:left">
                <div style="font-size:18px;font-weight:bold;margin-bottom:8px">✓ {{ __('fleet.station.valid') }}</div>
                <div style="font-size:26px;font-weight:bold;letter-spacing:.08em;margin-bottom:8px">{{ $fuelUp->vehicle->plate }}</div>
                <div>{{ $fuelUp->vehicle->brand }} {{ $fuelUp->vehicle->model }}</div>
                <div>{{ __('fleet.station.person') }}: <strong>{{ $fuelUp->driver?->name ?? $fuelUp->creator?->name }}</strong></div>
                <div>{{ __('fleet.fields.fuel') }}: <strong>{{ collect($fuelUp->vehicle->fuel_type->acceptedFuels())->map->getLabel()->implode(' / ') }}</strong></div>
                @if ($fuelUp->vehicle->tank_capacity)
                    <div>{{ __('fleet.station.max_volume') }}: <strong>{{ \App\Modules\Fleet\Services\FleetFormat::volume((float) $fuelUp->vehicle->tank_capacity) }}</strong></div>
                @endif
                @if ($fuelUp->station)
                    <div>{{ __('fleet.stations.singular_title') }}: <strong>{{ $fuelUp->station->name }}</strong></div>
                @endif
                <div style="margin-top:6px;font-size:13px">{{ __('fleet.fuel.code_valid_until', ['time' => $fuelUp->code_expires_at->timezone($tenant->license()->tenant('timezone') ?: config('kiban.timezone'))->format('H:i')]) }}</div>
            </div>
        @else
            <div style="background:#fde2e2;color:#b42318;border-radius:10px;padding:14px 16px;margin:16px 0;text-align:left">
                <div style="font-size:18px;font-weight:bold">✗ {{ __('fleet.station.invalid') }}</div>
                <div style="margin-top:6px">{{ __('fleet.station.invalid_help') }}</div>
            </div>
        @endif
    @endif

    <form method="post" action="{{ url('/posto') }}">
        @csrf
        <label for="code">{{ __('fleet.station.code') }}</label>
        <div class="address">
            <input id="code" name="code" required autofocus autocomplete="off" autocapitalize="characters" maxlength="20"
                   style="font-size:24px;letter-spacing:.2em;text-transform:uppercase;font-family:monospace" placeholder="ABC-123">
        </div>
        @error('code')
            <p style="color:#b42318;text-align:left;margin-top:8px">{{ $message }}</p>
        @enderror
        <button type="submit">{{ __('fleet.station.check') }}</button>
    </form>

    <p class="muted" style="margin-top:18px">{{ __('fleet.station.footer') }}</p>
</x-layout>
