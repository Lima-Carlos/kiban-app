<x-filament::section icon="heroicon-o-wrench-screwdriver">
    <x-slot name="heading">{{ __('field.my_orders.home_title') }}</x-slot>
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px">
        <div>
            <div style="font-size:15px">{{ trans_choice('field.my_orders.home_count', $today->count(), ['count' => $today->count()]) }}</div>
            <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
                @foreach ($today as $order)
                    <x-filament::badge :color="$order->status->getColor()">{{ $order->scheduled_at?->setTimezone(\App\Modules\Field\Filament\Orders\OrderResource::timezone())->format('H:i') }} · {{ $order->customer->displayName() }}</x-filament::badge>
                @endforeach
            </div>
        </div>
        <x-filament::button tag="a" size="xl" icon="heroicon-o-wrench-screwdriver" :href="$url">
            {{ __('field.my_orders.title') }}
        </x-filament::button>
    </div>
</x-filament::section>
