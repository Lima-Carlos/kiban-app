@php
    use App\Modules\TimeClock\Services\ScheduleCalendar;
    $total = collect($days)->sum(fn (array $day): int => $day['plan']['minutes'] ?? 0);
@endphp

<div style="display:flex;flex-direction:column;gap:2px">
    @foreach ($days as $day)
        @php($isToday = $day['date']->isToday())
        <div style="display:flex;justify-content:space-between;gap:10px;padding:6px 8px;border-radius:6px;{{ $isToday ? 'background:rgba(4,66,153,.08);' : '' }}{{ $day['plan'] ? '' : 'opacity:.7;' }}">
            <span style="min-width:120px;font-variant-numeric:tabular-nums">{{ ucfirst($day['date']->translatedFormat(__('timeclock.holidays.day_month_weekday_format'))) }}</span>
            <span style="flex:1;text-align:right">
                @if ($day['holiday'])
                    <x-filament::badge color="warning">{{ __('timeclock.employees.holiday') }}: {{ $day['holiday'] }}</x-filament::badge>
                @elseif ($day['plan'])
                    {{ $day['plan']['start'] }}–{{ $day['plan']['end'] }}
                    @if ($day['plan']['break_minutes'])
                        <span style="opacity:.7;font-size:13px">({{ __('timeclock.employees.break', ['minutes' => $day['plan']['break_minutes']]) }})</span>
                    @endif
                    · <strong>{{ ScheduleCalendar::hours($day['plan']['minutes']) }}</strong>
                @elseif ($day['schedule'])
                    {{ __('timeclock.employees.day_off') }}
                @else
                    {{ __('timeclock.employees.no_schedule') }}
                @endif
            </span>
        </div>
    @endforeach
    <div style="text-align:right;padding:8px;font-weight:600">{{ __('timeclock.employees.month_total', ['hours' => ScheduleCalendar::hours($total)]) }}</div>
</div>
