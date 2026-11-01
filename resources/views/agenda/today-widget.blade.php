@php
    use App\Modules\Agenda\Filament\CalendarPage;
    $today = $this->today();
    $boxes = ['scheduled' => 'gray', 'arrived' => 'warning', 'ready' => 'success', 'delivered' => 'primary', 'no_show' => 'danger'];
@endphp

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-calendar-days">
        <x-slot name="heading">{{ __('agenda.today.title') }}</x-slot>
        <x-slot name="description">{{ trans_choice('agenda.today.total', $today['total'], ['count' => $today['total']]) }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::button tag="a" :href="CalendarPage::getUrl()" color="gray" size="sm" icon="heroicon-o-calendar-days">{{ __('agenda.calendar.title') }}</x-filament::button>
        </x-slot>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px" data-agenda="today">
            @foreach ($boxes as $key => $color)
                <div style="border-radius:10px;padding:10px;background:rgba(127,127,127,.07);text-align:center">
                    <div style="font-size:1.6rem;font-weight:700">{{ $today[$key] }}</div>
                    <x-filament::badge :color="$color">{{ __('agenda.today.'.$key) }}</x-filament::badge>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
