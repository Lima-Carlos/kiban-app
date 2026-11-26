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
        <style>[data-pedidos=map] img.leaflet-tile { max-width: none !important; max-height: none !important; } .pd-pin { background: none; border: 0; }</style>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <div
            wire:ignore
            x-data="{ pins: @js($data['pins']), shop: @js($data['shop']), shopLabel: @js(__('pedidos.map.shop')) }"
            x-init="
                const start = () => {
                    const map = L.map($refs.map, { scrollWheelZoom: false });
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
                    const bounds = [];
                    // 26/11/2026: a pin with a little house (the owner found the small dot hard to see on the map).
                    const house = '<path d=\'M12 6.5 6 11.6V18h4.2v-4h3.6v4H18v-6.4z\' fill=\'#fff\'/>';
                    const store = '<path d=\'M6.5 7h11l1 3.2a2 2 0 0 1-3.8.8 2 2 0 0 1-3.4 0 2 2 0 0 1-3.4 0 2 2 0 0 1-3.4-.8zM7.5 12.6V18h9v-5.4\' fill=\'#fff\' stroke=\'#fff\' stroke-width=\'.6\' stroke-linejoin=\'round\'/>';
                    const pinIcon = (color, glyph, size) => L.divIcon({
                        className: 'pd-pin',
                        html: `<svg width='${size}' height='${size * 1.3}' viewBox='0 0 24 31' style='filter:drop-shadow(0 2px 3px rgba(0,0,0,.45))'><path d='M12 1C6 1 1.5 5.5 1.5 11.3c0 7.4 10.5 18.7 10.5 18.7s10.5-11.3 10.5-18.7C22.5 5.5 18 1 12 1z' fill='${color}' stroke='#fff' stroke-width='1.6'/><g transform='translate(0 -1.5)'>${glyph}</g></svg>`,
                        iconSize: [size, size * 1.3],
                        iconAnchor: [size / 2, size * 1.3],
                        popupAnchor: [0, -size * 1.15],
                        tooltipAnchor: [0, -size * 1.15],
                    });
                    const escape = (text) => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;');
                    pins.forEach((pin) => {
                        const marker = L.marker([pin.lat, pin.lng], { icon: pinIcon(pin.color, house, 40) }).addTo(map);
                        marker.bindTooltip(escape(pin.title), { direction: 'top' });
                        marker.bindPopup(`<a href='${pin.url}'>${escape(pin.title)}</a>`);
                        bounds.push([pin.lat, pin.lng]);
                    });
                    if (shop) {
                        L.marker([shop.lat, shop.lng], { icon: pinIcon('#39383A', store, 48), zIndexOffset: 1000 }).addTo(map).bindTooltip(escape(shopLabel), { direction: 'top', permanent: true });
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
