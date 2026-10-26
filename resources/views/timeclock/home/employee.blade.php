@php use App\Modules\TimeClock\Services\ScheduleCalendar; @endphp

<x-filament::section icon="heroicon-o-finger-print">
    <x-slot name="heading">{{ __('timeclock.home.title') }}</x-slot>
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px">
        <div>
            <div style="font-size:13px;opacity:.75">{{ ucfirst($today->translatedFormat(__('timeclock.holidays.day_month_weekday_format'))) }}</div>
            <div style="margin-top:2px">
                @if ($day['holiday'])
                    {{ __('timeclock.employees.holiday') }}: {{ $day['holiday'] }}
                @elseif ($day['plan'])
                    {{ __('timeclock.punches.expected', ['start' => $day['plan']['start'], 'end' => $day['plan']['end'], 'hours' => ScheduleCalendar::hours($day['plan']['minutes'])]) }}
                @else
                    {{ __('timeclock.employees.day_off') }}
                @endif
            </div>
            <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
                @forelse ($punches as $punch)
                    <x-filament::badge color="gray">{{ $punch->localTime()->format('H:i') }}</x-filament::badge>
                @empty
                    <span style="font-size:13px;opacity:.7">{{ __('timeclock.punches.none_today') }}</span>
                @endforelse
            </div>
        </div>
        <x-filament::button tag="a" size="xl" icon="heroicon-o-finger-print" :href="$url">
            {{ __('timeclock.punches.punch_now') }}
        </x-filament::button>
    </div>
</x-filament::section>
