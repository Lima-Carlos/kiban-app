@php
    use App\Modules\Field\Filament\Orders\OrderResource;
    use App\Modules\Field\Services\FieldFormat;
    $today = $this->today();
    $timezone = OrderResource::timezone();
    $time = fn ($moment): string => $moment->setTimezone($timezone)->format('H:i');
    $colors = ['late' => 'danger', 'working' => 'success', 'on_the_way' => 'info', 'free' => 'gray', 'off' => 'gray'];
    $row = 'display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid rgba(127,127,127,.2)';
@endphp

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-map">
        <x-slot name="heading">{{ __('field.dashboard.now') }}</x-slot>
        <x-slot name="description">{{ __('field.dashboard.now_help', $today['counts']) }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::button tag="a" :href="OrderResource::getUrl('agenda')" color="gray" size="sm" icon="heroicon-o-calendar-days">{{ __('field.agenda.title') }}</x-filament::button>
        </x-slot>

        @forelse ($today['technicians'] as $item)
            <div style="{{ $row }}">
                <div style="min-width:0">
                    <div style="font-weight:600">{{ $item['technician']->name }}</div>
                    <div style="font-size:13px;opacity:.8">
                        @if (in_array($item['state'], ['working', 'late', 'on_the_way'], true))
                            <a href="{{ OrderResource::getUrl('view', ['record' => $item['order']]) }}" style="text-decoration:underline">{{ $item['order']->label() }} · {{ $item['order']->customer->displayName() }}</a>
                            @if ($item['state'] === 'working')
                                @if ($item['since']) · {{ __('field.dashboard.since', ['time' => $time($item['since'])]) }} @endif
                            @else
                                · {{ $time($item['order']->scheduled_at) }}
                                @if ($item['order']->delay_minutes) · {{ __('field.dashboard.delay', ['minutes' => $item['order']->delay_minutes]) }} @endif
                            @endif
                        @elseif ($item['next'])
                            {{ __('field.dashboard.next', ['time' => $time($item['next']->scheduled_at), 'customer' => $item['next']->customer->displayName()]) }}
                        @else
                            {{ __('field.dashboard.nothing_more') }}
                        @endif
                    </div>
                </div>
                <div style="text-align:right;white-space:nowrap">
                    <x-filament::badge :color="$colors[$item['state']]">
                        {{ $item['state'] === 'late' ? __('field.dashboard.late_by', ['time' => FieldFormat::duration($item['minutes'])]) : __('field.dashboard.states.'.$item['state']) }}
                    </x-filament::badge>
                    <div style="font-size:12px;opacity:.7;margin-top:4px">{{ __('field.dashboard.done_of', ['done' => $item['done'], 'total' => $item['total']]) }}</div>
                </div>
            </div>
        @empty
            <p style="opacity:.7;font-size:14px">{{ __('field.helpers.no_technicians') }}</p>
        @endforelse

        @if ($today['counts']['unassigned'] > 0)
            <p style="margin-top:10px;font-size:13px">
                <a href="{{ OrderResource::getUrl('agenda') }}" style="text-decoration:underline">{{ trans_choice('field.dashboard.unassigned', $today['counts']['unassigned'], ['count' => $today['counts']['unassigned']]) }}</a>
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
