@php
    use App\Modules\Agenda\Services\AgendaFormat;
@endphp
<x-filament::section icon="heroicon-o-calendar-days">
    <x-slot name="heading">{{ __('agenda.home.my_day') }}</x-slot>
    @forelse ($appointments as $appointment)
        <div style="display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid rgba(127,127,127,.2)">
            <div>
                <strong>{{ AgendaFormat::time($appointment->starts_at) }}</strong> · {{ $appointment->title() }}
                <div style="font-size:13px;opacity:.75">{{ $appointment->servicesLabel() }}</div>
            </div>
            <x-filament::badge :color="$appointment->status->getColor()">{{ $appointment->status->getLabel() }}</x-filament::badge>
        </div>
    @empty
        <p style="margin:0;opacity:.7">{{ __('agenda.home.nothing_today') }}</p>
    @endforelse
    <div style="margin-top:12px">
        <x-filament::button tag="a" :href="$url" icon="heroicon-o-calendar-days">{{ __('agenda.calendar.title') }}</x-filament::button>
    </div>
</x-filament::section>
