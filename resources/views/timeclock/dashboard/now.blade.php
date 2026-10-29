@php
    use App\Modules\TimeClock\Filament\TimesheetPage;
    use App\Modules\TimeClock\Services\ScheduleCalendar;
    $now = $this->now();
    $row = 'display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.2);color:inherit;text-decoration:none';
    $link = fn ($employee): string => TimesheetPage::getUrl(['employee' => $employee->getKey(), 'month' => now()->format('Y-m')]);
    $columns = [
        'working' => ['color' => 'success', 'items' => $now['working']],
        'late' => ['color' => 'danger', 'items' => $now['late']],
        'absent' => ['color' => 'danger', 'items' => $now['absent']],
        'break' => ['color' => 'warning', 'items' => $now['break']],
        'later' => ['color' => 'gray', 'items' => $now['later']],
        'done' => ['color' => 'info', 'items' => $now['done']],
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-clock">
        <x-slot name="heading">{{ __('timeclock.dashboard.now') }}</x-slot>
        <x-slot name="description">
            {{ __('timeclock.dashboard.now_help', ['working' => count($now['working']), 'expected' => $now['expected']]) }}
            @if ($now['outside'] > 0)
                · {{ trans_choice('timeclock.attention.outside', $now['outside'], ['count' => $now['outside']]) }}
            @endif
        </x-slot>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px">
            @foreach ($columns as $key => $column)
                @continue ($column['items'] === [] && in_array($key, ['break', 'done', 'later', 'absent'], true))
                <div>
                    <div style="display:flex;align-items:center;gap:6px;font-weight:600;margin-bottom:4px">
                        <x-filament::badge :color="$column['color']">{{ count($column['items']) }}</x-filament::badge>
                        {{ __('timeclock.dashboard.states.'.$key) }}
                    </div>
                    @forelse ($column['items'] as $item)
                        <a href="{{ $link($item['employee']) }}" style="{{ $row }}">
                            <span>{{ $item['employee']->person?->name }}</span>
                            <span style="opacity:.75;font-size:13px;white-space:nowrap">
                                @if ($key === 'late')
                                    {{ __('timeclock.dashboard.late_by', ['time' => $item['minutes'] < 60 ? __('timeclock.dashboard.minutes', ['minutes' => $item['minutes']]) : intdiv($item['minutes'], 60).'h'.str_pad((string) ($item['minutes'] % 60), 2, '0', STR_PAD_LEFT)]) }}
                                @elseif ($key === 'absent')
                                    {{ __('timeclock.dashboard.expected_at', ['start' => $item['start']]) }}
                                @elseif ($key === 'later')
                                    {{ $item['start'] }}
                                @else
                                    {{ __('timeclock.dashboard.since', ['time' => $item['since']->format('H:i')]) }}
                                @endif
                            </span>
                        </a>
                    @empty
                        <p style="opacity:.7;font-size:14px">{{ __('timeclock.dashboard.nobody') }}</p>
                    @endforelse
                </div>
            @endforeach
        </div>

        @if ($now['excused'] !== [])
            <p style="margin-top:10px;font-size:13px;opacity:.75">{{ __('timeclock.dashboard.excused', ['names' => collect($now['excused'])->map(fn ($employee) => $employee->person?->name)->implode(', ')]) }}</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
