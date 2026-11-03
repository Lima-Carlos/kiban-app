{{-- "My stock" of a technician: items and serial numbers they carry, as cards (works well on the phone). --}}
@php($balances = $this->balances())
@php($units = $this->units())
<x-filament-panels::page>
    <x-filament::section :heading="__('stock.my.items')" icon="heroicon-o-cube">
        @if ($balances->isEmpty())
            <p style="margin:0;opacity:.7">{{ __('stock.my.empty') }}</p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr));gap:10px" data-stock="mine">
                @foreach ($balances as $balance)
                    <div style="border:1px solid rgba(127,127,127,.25);border-radius:10px;padding:10px 12px">
                        <div style="font-weight:600">{{ $balance->item->name }}</div>
                        <div style="font-size:20px;font-weight:700;margin-top:2px;{{ $balance->quantity < 0 ? 'color:#dc2626' : '' }}">{{ \App\Modules\Stock\Services\StockFormat::quantity((float) $balance->quantity, $balance->item->unit) }}</div>
                    </div>
                @endforeach
            </div>
            <p style="font-size:13px;opacity:.7;margin:10px 0 0">{{ __('stock.my.help') }}</p>
        @endif
    </x-filament::section>

    @if ($units->isNotEmpty())
        <x-filament::section :heading="__('stock.my.units')" icon="heroicon-o-qr-code">
            <ul style="margin:0;padding-left:18px;line-height:1.8">
                @foreach ($units as $unit)
                    <li><strong style="font-family:monospace">{{ $unit->serial }}</strong> · {{ $unit->item->name }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
