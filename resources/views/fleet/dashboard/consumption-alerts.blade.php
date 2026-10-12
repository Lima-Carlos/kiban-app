<x-filament-widgets::widget>
    <x-filament::section :icon="'heroicon-o-exclamation-triangle'">
        <x-slot name="heading">{{ __('fleet.dashboard.alerts_title') }}</x-slot>
        <x-slot name="description">{{ __('fleet.dashboard.alerts_help') }}</x-slot>

        @php($alerts = $this->getAlerts())

        @forelse ($alerts as $alert)
            <a href="{{ \App\Modules\Fleet\Filament\Vehicles\VehicleResource::getUrl('view', ['record' => $alert['vehicle']]) }}"
               style="display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid rgba(127,127,127,.2);text-decoration:none;color:inherit">
                <span><strong>{{ $alert['vehicle']->plate }}</strong> · {{ $alert['vehicle']->brand }} {{ $alert['vehicle']->model }}</span>
                <span style="color:#dc2626">
                    {{ \App\Modules\Fleet\Services\FleetFormat::consumption($alert['last']) }}
                    ({{ __('fleet.dashboard.alert_line', ['average' => \App\Modules\Fleet\Services\FleetFormat::consumption($alert['average']), 'percent' => $alert['drop_percent']]) }})
                </span>
            </a>
        @empty
            <p style="opacity:.7">{{ __('fleet.dashboard.no_alerts') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
