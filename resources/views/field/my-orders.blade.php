<x-filament-panels::page>
    @php
        $groups = $this->groups();
        $timezone = \App\Modules\Field\Filament\Orders\OrderResource::timezone();
        $card = 'display:block;padding:12px 14px;border-radius:12px;border:1px solid rgba(127,127,127,.25);margin-bottom:10px;background:var(--color-white, #fff)';
    @endphp

    @foreach (['today', 'paused', 'next'] as $group)
        @if ($group === 'today' || $groups[$group]->isNotEmpty())
            <x-filament::section :heading="__('field.my_orders.'.$group)">
                @forelse ($groups[$group] as $order)
                    @php($url = \App\Modules\Field\Filament\ExecuteOrderPage::getUrl(['order' => $order->getKey()]))
                    <div style="{{ $card }};border-left:4px solid {{ $order->serviceType->color ?: '#044299' }}" data-my-order="{{ $url }}">
                        <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
                            <strong style="font-size:16px">
                                @if ($order->scheduled_at)
                                    {{ $group === 'today' ? '' : ucfirst($order->scheduled_at->setTimezone($timezone)->translatedFormat('D d/m')).' · ' }}{{ $order->scheduled_at->setTimezone($timezone)->format('H:i') }}
                                @endif
                            </strong>
                            <x-filament::badge size="sm" :color="$order->status->getColor()">{{ $order->status->getLabel() }}</x-filament::badge>
                        </div>
                        <div style="font-weight:600;font-size:16px;margin-top:4px">{{ $order->customer->displayName() }}</div>
                        <div style="font-size:13px;opacity:.8">{{ $order->serviceType->name }} · {{ $order->label() }}</div>
                        @if ($order->site)
                            <div style="font-size:13px;opacity:.8;margin-top:2px">{{ $order->site->address() }}</div>
                        @endif
                        @if ($order->status === \App\Modules\Field\Enums\OrderStatus::Paused && $order->pause_reason)
                            <div style="font-size:13px;color:#b45309;margin-top:4px">{{ $order->pause_reason }}</div>
                        @endif
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <x-filament::button :href="$url" tag="a" icon="heroicon-o-play">{{ $order->status === \App\Modules\Field\Enums\OrderStatus::Scheduled ? __('field.my_orders.start') : __('field.my_orders.continue') }}</x-filament::button>
                            @if ($directions = \App\Modules\Field\Filament\MyOrdersPage::directionsUrl($order))
                                <x-filament::button :href="$directions" tag="a" target="_blank" color="gray" icon="heroicon-o-map">{{ __('field.my_orders.directions') }}</x-filament::button>
                            @endif
                            @if ($order->customer->phone)
                                <x-filament::button :href="'https://wa.me/'.$order->customer->phone" tag="a" target="_blank" color="gray" icon="heroicon-o-chat-bubble-left-right">WhatsApp</x-filament::button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p style="opacity:.7;margin:0">{{ __('field.my_orders.none_today') }}</p>
                @endforelse
            </x-filament::section>
        @endif
    @endforeach

    {{-- With signal, each order of the list is kept on the phone (and the queue script it uses), so the day's work opens without signal too. --}}
    <script src="{{ asset('js/field-queue.js') }}?v=2"></script>
    <script>
        (() => {
            if (!navigator.onLine || !('serviceWorker' in navigator)) { return; }
            document.querySelectorAll('[data-my-order]').forEach((card) => {
                fetch(card.dataset.myOrder, { headers: { 'X-Kiban-Keep': '1' }, credentials: 'same-origin' }).catch(() => {});
            });
        })();
    </script>
</x-filament-panels::page>
