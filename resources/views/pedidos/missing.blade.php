@php
    use App\Modules\Pedidos\Filament\Clients\ClientResource;
    use App\Modules\Pedidos\Services\PedidosFormat;
    use App\Support\Country;
    $clients = $this->clients();
    $row = 'padding:12px 0;border-bottom:1px solid rgba(127,127,127,.2)';
    $input = 'width:100%;border-radius:8px;border:1px solid rgba(127,127,127,.35);padding:8px 10px;background:transparent;color:inherit';
@endphp
<x-filament-panels::page>
    <x-filament::section :heading="__('pedidos.missing.message')" :description="__('pedidos.missing.message_help')" collapsible>
        <textarea rows="3" style="{{ $input }}" wire:model.blur="message" maxlength="1000" data-pedidos="promo-message"></textarea>
        <div style="margin-top:8px">
            <x-filament::button size="sm" color="gray" wire:click="saveMessage">{{ __('pedidos.missing.save_message') }}</x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="description">{{ trans_choice('pedidos.missing.help', $clients->count(), ['count' => $clients->count()]) }}</x-slot>
        <label style="display:flex;gap:8px;align-items:center;font-size:14px;margin-bottom:8px">
            <input type="checkbox" wire:model.live="withLate">
            <span>{{ __('pedidos.missing.with_late') }}</span>
        </label>

        @forelse ($clients as $item)
            @php($client = $item['client'])
            <div style="{{ $row }}" wire:key="missing-{{ $client->getKey() }}" data-pedidos="missing">
                <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start">
                    <div style="min-width:0">
                        <div style="display:flex;gap:8px;align-items:center">
                            <a href="{{ ClientResource::getUrl('view', ['record' => $client]) }}" style="font-weight:700">{{ $client->name }}</a>
                            <x-filament::badge :color="$item['row']['rhythm']->getColor()">{{ $item['row']['rhythm']->getLabel() }}</x-filament::badge>
                        </div>
                        <div style="font-size:13px;opacity:.75">{{ Country::formatPhone($client->phone) }} · {{ PedidosFormat::rhythmLine($item['row']) }}</div>
                    </div>
                    <div>
                        @if ($url = $this->whatsapp($client))
                            <x-filament::button size="sm" tag="a" target="_blank" :href="$url" :color="$client->details?->last_promo_at ? 'gray' : 'success'" icon="heroicon-o-chat-bubble-left-right" wire:click="markSent({{ $client->getKey() }})">
                                {{ $client->details?->last_promo_at ? __('pedidos.missing.sent_on', ['date' => PedidosFormat::date($client->details->last_promo_at)]) : __('pedidos.missing.send') }}
                            </x-filament::button>
                        @else
                            <span style="font-size:12px;opacity:.7">{{ __('pedidos.missing.no_consent') }}</span>
                        @endif
                    </div>
                </div>
                <ul style="margin:6px 0 0;padding-left:18px;font-size:13px">
                    @foreach ($item['orders'] as $order)
                        <li>{{ PedidosFormat::orderLine($order) }}</li>
                    @endforeach
                </ul>
            </div>
        @empty
            <p style="margin:0;opacity:.7">{{ __('pedidos.missing.none') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
