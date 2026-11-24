@php
    use App\Modules\Pedidos\Filament\BoardPage;
    use App\Modules\Pedidos\Filament\OrderDeskPage;
    use App\Support\Money;
    $today = $this->today();
    $boxes = ['new' => 'danger', 'preparing' => 'warning', 'ready' => 'success', 'out' => 'info', 'delivered' => 'gray'];
@endphp

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-shopping-bag">
        <x-slot name="heading">{{ __('pedidos.today.title') }}</x-slot>
        <x-slot name="description">{{ trans_choice('pedidos.today.total', $today['orders'], ['count' => $today['orders'], 'sales' => Money::format($today['sales_cents'])]) }}</x-slot>
        <x-slot name="afterHeader">
            <div style="display:flex;gap:6px">
                @if (OrderDeskPage::canAccess())
                    <x-filament::button tag="a" :href="OrderDeskPage::getUrl()" size="sm" icon="heroicon-o-plus">{{ __('pedidos.desk.new_order') }}</x-filament::button>
                @endif
                <x-filament::button tag="a" :href="BoardPage::getUrl()" color="gray" size="sm" icon="heroicon-o-fire">{{ __('pedidos.board.page_title') }}</x-filament::button>
            </div>
        </x-slot>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px" data-pedidos="today">
            @foreach ($boxes as $key => $color)
                <div style="border-radius:10px;padding:10px;background:rgba(127,127,127,.07);text-align:center">
                    <div style="font-size:1.6rem;font-weight:700">{{ $today[$key] }}</div>
                    <x-filament::badge :color="$color">{{ __('pedidos.today.'.$key) }}</x-filament::badge>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
