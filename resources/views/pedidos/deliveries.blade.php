@php
    use App\Modules\Pedidos\Services\PedidosFormat;
    use App\Modules\Pedidos\Filament\PedidosSettingsPage;
    $ready = $this->ready();
    $suggestion = $this->suggestion();
    $drivers = $this->drivers();
    $runs = $this->runs();
    $row = 'display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(127,127,127,.2);flex-wrap:wrap';
    $chip = 'border-radius:999px;padding:6px 12px;border:1px solid rgba(127,127,127,.35);cursor:pointer;font-size:14px;background:transparent;color:inherit';
    $chipOn = 'border-radius:999px;padding:6px 12px;border:1px solid #044299;cursor:pointer;font-size:14px;background:#044299;color:#fff';
@endphp
<x-filament-panels::page>
    <div wire:poll.30s></div>

    @unless ($this->shopHasPoint())
        <x-filament::section>
            <p style="margin:0">{{ __('pedidos.deliveries.no_shop_point') }}
                @if (PedidosSettingsPage::canAccess())
                    <x-filament::link :href="PedidosSettingsPage::getUrl()">{{ __('pedidos.settings.title') }}</x-filament::link>
                @endif
            </p>
        </x-filament::section>
    @endunless

    <x-filament::section :heading="__('pedidos.deliveries.ready')" :description="__('pedidos.deliveries.ready_help')">
        @forelse ($ready as $order)
            <label style="{{ $row }};cursor:pointer" wire:key="ready-{{ $order->getKey() }}">
                <div style="display:flex;gap:10px;align-items:flex-start;min-width:0">
                    <input type="checkbox" value="{{ $order->getKey() }}" wire:model.live="selected" style="margin-top:4px">
                    <div style="min-width:0">
                        <div style="font-weight:600">#{{ $order->number }} · {{ $order->customerName() }}</div>
                        <div style="font-size:13px;opacity:.75">{{ $order->address ?: '—' }}{{ $order->site?->reference ? ' ('.$order->site->reference.')' : '' }}</div>
                        @unless ($order->site?->hasPoint())
                            <div style="font-size:12px;color:#b45309">{{ __('pedidos.deliveries.no_point') }}</div>
                        @endunless
                    </div>
                </div>
                <span style="font-size:13px;opacity:.75">{{ __('pedidos.deliveries.waiting', ['time' => PedidosFormat::minutes($order->minutes())]) }}</span>
            </label>
        @empty
            <p style="margin:0;opacity:.7">{{ __('pedidos.deliveries.none_ready') }}</p>
        @endforelse

        @if ($suggestion)
            <div style="margin-top:14px;padding:12px;border-radius:12px;background:rgba(4,66,153,.07)" data-pedidos="suggestion">
                <div style="font-weight:700;margin-bottom:6px">{{ __('pedidos.deliveries.suggested', ['km' => number_format($suggestion['distance'] / 1000, 1, __('field.decimal_separator'), '')]) }}</div>
                <ol style="margin:0 0 8px;padding-left:20px">
                    @foreach ($suggestion['orders'] as $order)
                        <li>#{{ $order->number }} · {{ $order->customerName() }} · <span style="opacity:.75">{{ $order->address }}</span></li>
                    @endforeach
                </ol>
                @if ($suggestion['missing'] !== [])
                    <p style="font-size:12px;margin:0 0 8px;color:#b45309">{{ __('pedidos.deliveries.missing_at_end') }}</p>
                @endif

                <div style="font-size:13px;font-weight:600;margin:8px 0 4px">{{ __('pedidos.deliveries.driver') }}</div>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    @forelse ($drivers as $driver)
                        <button type="button" style="{{ $this->driverId === $driver->getKey() ? $chipOn : $chip }}" wire:click="$set('driverId', {{ $driver->getKey() }})">{{ $driver->name }}</button>
                    @empty
                        <span style="font-size:13px;opacity:.7">{{ __('pedidos.deliveries.no_drivers') }}</span>
                    @endforelse
                </div>

                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">
                    <x-filament::button icon="heroicon-o-truck" wire:click="send">{{ __('pedidos.deliveries.send') }}</x-filament::button>
                    @if ($suggestion['maps_url'])
                        <x-filament::button color="gray" tag="a" target="_blank" :href="$suggestion['maps_url']" icon="heroicon-o-map">{{ __('pedidos.deliveries.maps') }}</x-filament::button>
                    @endif
                </div>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('pedidos.deliveries.on_the_street')">
        @forelse ($runs as $run)
            <div style="margin-bottom:14px" wire:key="run-{{ $run->getKey() }}" data-pedidos="run">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                    <div style="font-weight:700">{{ $run->driver?->name ?? __('pedidos.deliveries.no_driver') }} · {{ __('pedidos.deliveries.left_at', ['time' => PedidosFormat::time($run->started_at)]) }}</div>
                    @if ($url = $this->runMapsUrl($run))
                        <x-filament::button size="sm" color="gray" tag="a" target="_blank" :href="$url" icon="heroicon-o-map">{{ __('pedidos.deliveries.maps') }}</x-filament::button>
                    @endif
                </div>
                @foreach ($run->orders as $order)
                    <div style="{{ $row }}">
                        <div style="min-width:0">
                            <div style="font-weight:600">{{ $order->run_position }}. #{{ $order->number }} · {{ $order->customerName() }}</div>
                            <div style="font-size:13px;opacity:.75">{{ $order->address }}</div>
                        </div>
                        @if ($order->status === \App\Modules\Pedidos\Enums\OrderStatus::Out)
                            <x-filament::button size="sm" color="success" wire:click="delivered({{ $order->getKey() }})">{{ __('pedidos.board.deliver') }}</x-filament::button>
                        @else
                            <x-filament::badge :color="$order->status->getColor()">{{ $order->status->getLabel() }}</x-filament::badge>
                        @endif
                    </div>
                @endforeach
            </div>
        @empty
            <p style="margin:0;opacity:.7">{{ __('pedidos.deliveries.none_out') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
