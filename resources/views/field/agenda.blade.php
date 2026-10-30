<x-filament-panels::page>
    @php
        $resource = \App\Modules\Field\Filament\Orders\OrderResource::class;
        $columns = $this->getColumns();
        $unscheduled = $this->getUnscheduled();
        $pins = $this->pins($columns);
        $timezone = $resource::timezone();
        $card = 'display:block;padding:8px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.25);text-decoration:none;color:inherit;margin-bottom:8px;background:var(--color-white, #fff)';
    @endphp

    {{-- The day: previous, today, next. --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:8px">
            <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" :label="__('field.agenda.previous')" />
            <div style="font-size:1.15rem;font-weight:600">{{ ucfirst($this->date()->locale(app()->getLocale())->translatedFormat(__('field.agenda.day_format'))) }}</div>
            <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" :label="__('field.agenda.next')" />
        </div>
        <x-filament::button color="gray" size="sm" wire:click="today">{{ __('field.agenda.today') }}</x-filament::button>
    </div>

    @if ($columns === [])
        <x-filament::section>
            <p style="margin:0">{{ __('field.agenda.no_technicians') }}</p>
        </x-filament::section>
    @endif

    {{-- One column per technician; on the phone, one under the other. --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;align-items:start">
        @foreach ($columns as $column)
            <div data-agenda="technician" style="border-radius:12px;padding:10px;background:rgba(127,127,127,.06)">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                    <span style="width:12px;height:12px;border-radius:999px;background:{{ $column['color'] }}"></span>
                    <strong>{{ $column['technician']->name }}</strong>
                    <span style="margin-left:auto;font-size:12px;opacity:.7">{{ trans_choice('field.agenda.count', $column['orders']->count(), ['count' => $column['orders']->count()]) }}</span>
                </div>
                @forelse ($column['orders'] as $order)
                    <a href="{{ $resource::getUrl('view', ['record' => $order]) }}" style="{{ $card }};border-left:4px solid {{ $order->serviceType->color ?: $column['color'] }}">
                        <div style="display:flex;justify-content:space-between;gap:6px;font-size:13px">
                            <strong>{{ $order->scheduled_at->setTimezone($timezone)->format('H:i') }}–{{ $order->endsAt()->setTimezone($timezone)->format('H:i') }}</strong>
                            <x-filament::badge size="sm" :color="$order->status->getColor()">{{ $order->status->getLabel() }}</x-filament::badge>
                        </div>
                        <div style="font-weight:600;margin-top:2px">{{ $order->customer->displayName() }}</div>
                        <div style="font-size:12px;opacity:.75">{{ $order->serviceType->name }} · {{ $order->label() }}</div>
                        @if ($order->site)
                            <div style="font-size:12px;opacity:.75">{{ collect([$order->site->district, $order->site->city])->filter()->implode(' - ') }}</div>
                        @endif
                    </a>
                @empty
                    <p style="font-size:13px;opacity:.6;margin:4px 0">{{ __('field.agenda.free') }}</p>
                @endforelse
            </div>
        @endforeach

        {{-- Orders without a time yet. --}}
        <div data-agenda="unscheduled" style="border-radius:12px;padding:10px;border:1px dashed rgba(127,127,127,.5)">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <strong>{{ __('field.agenda.unscheduled') }}</strong>
                <span style="margin-left:auto;font-size:12px;opacity:.7">{{ $unscheduled->count() }}</span>
            </div>
            @forelse ($unscheduled as $order)
                <a href="{{ $resource::getUrl('view', ['record' => $order]) }}" style="{{ $card }}">
                    <div style="display:flex;justify-content:space-between;gap:6px;font-size:13px">
                        <strong>{{ $order->label() }}</strong>
                        @if ($order->priority->value !== 'normal')
                            <x-filament::badge size="sm" :color="$order->priority->getColor()">{{ $order->priority->getLabel() }}</x-filament::badge>
                        @endif
                    </div>
                    <div style="font-weight:600;margin-top:2px">{{ $order->customer->displayName() }}</div>
                    <div style="font-size:12px;opacity:.75">{{ $order->serviceType->name }}</div>
                </a>
            @empty
                <p style="font-size:13px;opacity:.6;margin:4px 0">{{ __('field.agenda.nothing_to_schedule') }}</p>
            @endforelse
        </div>
    </div>

    {{-- The map of the day (OpenStreetMap, free). --}}
    <x-filament::section :heading="__('field.agenda.map')" :description="$pins === [] ? __('field.agenda.map_empty') : null" collapsible>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <div
            wire:key="agenda-map-{{ $this->day }}"
            wire:ignore
            x-data="{ pins: @js($pins) }"
            x-init="
                const start = () => {
                    const map = L.map($refs.map, { scrollWheelZoom: false });
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
                    const bounds = [];
                    pins.forEach((pin) => {
                        const marker = L.circleMarker([pin.lat, pin.lng], { radius: 9, color: '#fff', weight: 2, fillColor: pin.color, fillOpacity: 1 }).addTo(map);
                        marker.bindPopup(`<a href='${pin.url}'>${pin.title.replace(/</g, '&lt;')}</a>`);
                        bounds.push([pin.lat, pin.lng]);
                    });
                    bounds.length ? map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 }) : map.setView([-15.8, -47.9], 4);
                };
                if (window.L) { start(); } else {
                    const script = document.createElement('script');
                    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
                    script.crossOrigin = '';
                    script.onload = start;
                    document.head.appendChild(script);
                }
            "
        >
            <div x-ref="map" data-agenda="map" style="height:360px;border-radius:12px;z-index:0"></div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
