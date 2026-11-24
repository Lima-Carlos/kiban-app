@php
    use App\Modules\Pedidos\Enums\Rhythm;
    $data = $this->data();
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center" data-pedidos="legend">
            @foreach (Rhythm::cases() as $rhythm)
                <span style="display:inline-flex;align-items:center;gap:6px;font-size:14px">
                    <span style="width:12px;height:12px;border-radius:50%;background:{{ $rhythm->hex() }};display:inline-block"></span>
                    {{ $rhythm->getLabel() }}: <strong>{{ $data['counts'][$rhythm->value] }}</strong>
                </span>
            @endforeach
        </div>
        @if ($data['without_point'] > 0)
            <p style="font-size:13px;opacity:.75;margin:8px 0 0">{{ trans_choice('pedidos.map.without_point', $data['without_point'], ['count' => $data['without_point']]) }}</p>
        @endif
    </x-filament::section>

    {{-- The map (OpenStreetMap, free). --}}
    <x-filament::section :description="$data['pins'] === [] ? __('pedidos.map.empty') : null">
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <div
            wire:ignore
            x-data="{ pins: @js($data['pins']), shop: @js($data['shop']), shopLabel: @js(__('pedidos.map.shop')) }"
            x-init="
                const start = () => {
                    const map = L.map($refs.map, { scrollWheelZoom: false });
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
                    const bounds = [];
                    pins.forEach((pin) => {
                        const marker = L.circleMarker([pin.lat, pin.lng], { radius: 8, color: '#fff', weight: 2, fillColor: pin.color, fillOpacity: 1 }).addTo(map);
                        marker.bindPopup(`<a href='${pin.url}'>${pin.title.replace(/</g, '&lt;')}</a>`);
                        bounds.push([pin.lat, pin.lng]);
                    });
                    if (shop) {
                        L.circleMarker([shop.lat, shop.lng], { radius: 11, color: '#fff', weight: 3, fillColor: '#044299', fillOpacity: 1 }).addTo(map).bindPopup(shopLabel);
                        bounds.push([shop.lat, shop.lng]);
                    }
                    bounds.length ? map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 }) : map.setView([-15.8, -47.9], 4);
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
            <div x-ref="map" data-pedidos="map" style="height:480px;border-radius:12px;z-index:0"></div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
