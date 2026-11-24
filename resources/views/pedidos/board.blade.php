@php
    use App\Modules\Pedidos\Filament\DeliveriesPage;
    use App\Modules\Pedidos\Filament\Orders\OrderResource;
    use App\Modules\Pedidos\Services\PedidosFormat;
    $columns = $this->columns();
    $cooks = $this->cooks();
    $canWork = $this->canWork();
    $card = 'border-radius:12px;padding:10px;background:rgba(127,127,127,.08);margin-bottom:10px';
@endphp
<x-filament-panels::page>
    <div wire:poll.15s style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;align-items:start" data-pedidos="board">
        @foreach ($columns as $status => $orders)
            <div data-pedidos="column-{{ $status }}">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <div style="font-weight:700">{{ __('pedidos.enums.status.'.$status) }}</div>
                    <x-filament::badge :color="\App\Modules\Pedidos\Enums\OrderStatus::from($status)->getColor()">{{ $orders->count() }}</x-filament::badge>
                </div>

                @forelse ($orders as $order)
                    <div style="{{ $card }}" wire:key="order-{{ $order->getKey() }}">
                        <div style="display:flex;justify-content:space-between;gap:8px">
                            <a href="{{ OrderResource::getUrl('view', ['record' => $order]) }}" style="font-weight:800">#{{ $order->number }}</a>
                            <span style="font-size:13px;opacity:.75" title="{{ PedidosFormat::time($order->created_at) }}">{{ PedidosFormat::minutes($order->minutes()) }}</span>
                        </div>
                        <div style="font-weight:600">{{ $order->customerName() }}</div>
                        <div style="font-size:12px;opacity:.75">{{ $order->fulfillment->getLabel() }} · {{ $order->source->getLabel() }}</div>
                        <ul style="margin:6px 0 0;padding-left:18px;font-size:14px">
                            @foreach ($order->lines as $line)
                                <li>{{ $line->label() }}</li>
                            @endforeach
                        </ul>
                        @if ($order->notes)
                            <div style="font-size:13px;margin-top:4px;color:#b45309">{{ $order->notes }}</div>
                        @endif
                        @if ($order->preparer)
                            <div style="font-size:12px;opacity:.75;margin-top:4px">{{ __('pedidos.orders.prepared_by', ['name' => $order->preparer->name]) }}</div>
                        @endif
                        @if ($order->run?->driver)
                            <div style="font-size:12px;opacity:.75">{{ __('pedidos.orders.delivered_by', ['name' => $order->run->driver->name]) }}</div>
                        @endif

                        @if ($canWork)
                            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
                                @if ($status === 'new')
                                    @if ($cooks->count() > 1 && $cooks->count() <= 6)
                                        @foreach ($cooks as $cook)
                                            <x-filament::button size="xs" color="warning" wire:click="start({{ $order->getKey() }}, {{ $cook->getKey() }})">{{ __('pedidos.board.start_by', ['name' => \Illuminate\Support\Str::before($cook->name, ' ')]) }}</x-filament::button>
                                        @endforeach
                                    @else
                                        <x-filament::button size="xs" color="warning" wire:click="start({{ $order->getKey() }}{{ $cooks->count() === 1 ? ', '.$cooks->first()->getKey() : '' }})">{{ __('pedidos.board.start') }}</x-filament::button>
                                    @endif
                                @endif
                                @if (in_array($status, ['new', 'preparing'], true))
                                    <x-filament::button size="xs" color="success" wire:click="ready({{ $order->getKey() }})">{{ __('pedidos.board.ready') }}</x-filament::button>
                                @endif
                                @if ($status === 'ready' && ! $order->isDelivery())
                                    <x-filament::button size="xs" color="success" wire:click="deliver({{ $order->getKey() }})">{{ __('pedidos.board.picked_up') }}</x-filament::button>
                                @endif
                                @if ($status === 'ready' && $order->isDelivery() && DeliveriesPage::canAccess())
                                    <x-filament::button size="xs" color="info" tag="a" :href="DeliveriesPage::getUrl()">{{ __('pedidos.board.to_deliveries') }}</x-filament::button>
                                @endif
                                <x-filament::button size="xs" color="gray" tag="a" target="_blank" :href="route('pedidos.ticket', ['order' => $order->getKey()])" icon="heroicon-o-printer">{{ __('pedidos.ticket.button') }}</x-filament::button>
                            </div>
                        @endif
                    </div>
                @empty
                    <p style="font-size:13px;opacity:.6;margin:4px 0">{{ __('pedidos.board.none') }}</p>
                @endforelse
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
