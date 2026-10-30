@php
    use App\Modules\TimeClock\Models\Adjustment;
    use App\Modules\TimeClock\Services\ScheduleCalendar;
    $h = fn (int $minutes): string => $minutes === 0 ? '—' : ScheduleCalendar::hours($minutes);
    $employee = $this->employee();
    $data = $this->monthData();
    $days = $data['days'];
    $totals = $data['totals'];
    $bank = $data['bank'];
    $closing = $data['closing'];
    $cell = 'padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.18);vertical-align:top';
@endphp

<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
        @if ($this->seesEveryone())
            <select wire:model.live="employeeId" style="min-width:240px;padding:8px 10px;border-radius:8px;border:1px solid rgba(127,127,127,.35);background:transparent">
                @foreach ($this->employeeOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @else
            <strong>{{ $employee?->person?->name }}</strong>
        @endif

        <div style="display:flex;align-items:center;gap:8px">
            <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" wire:click="previousMonth" :label="__('timeclock.timesheet.previous')" />
            <strong style="min-width:150px;text-align:center">{{ ucfirst($this->start()->translatedFormat('F Y')) }}</strong>
            <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" wire:click="nextMonth" :label="__('timeclock.timesheet.next')" />
        </div>
    </div>

    @if ($employee === null)
        <x-filament::section>
            <p style="opacity:.75">{{ __('timeclock.employees.empty') }}</p>
        </x-filament::section>
    @else
        @if ($closing)
            <x-filament::section compact>
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                    <x-filament::badge :color="match ($closing->status()) { 'signed' => 'success', 'disputed' => 'danger', default => 'info' }">{{ __('timeclock.closing.statuses.'.$closing->status()) }}</x-filament::badge>
                    <span style="font-size:14px">
                        @if ($closing->signed_at)
                            {{ __('timeclock.closing.signed_on', ['date' => $closing->signed_at->timezone(\Filament\Support\Facades\FilamentTimezone::get())->translatedFormat(__('app.datetime_format'))]) }}
                        @elseif ($closing->disputed_at)
                            {{ __('timeclock.closing.disputed_on', ['date' => $closing->disputed_at->timezone(\Filament\Support\Facades\FilamentTimezone::get())->translatedFormat(__('app.date_format'))]) }} {{ $closing->dispute_reason }}
                        @else
                            {{ $this->isOwn() ? __('timeclock.closing.waiting_you') : __('timeclock.closing.waiting_employee') }}
                        @endif
                    </span>
                </div>
            </x-filament::section>
        @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
            @foreach ([
                'expected' => $totals['expected'], 'worked' => $totals['worked'], 'extra' => $totals['extra'],
                'extra_rest' => $totals['extra_rest'], 'deficit' => $totals['deficit'] - $totals['absence_minutes'], 'night' => $totals['night_reduced'],
            ] as $key => $minutes)
                <x-filament::section compact>
                    <div style="font-size:12px;opacity:.7">{{ __('timeclock.timesheet.totals.'.$key) }}</div>
                    <div style="font-size:20px;font-weight:bold">{{ $h($minutes) }}</div>
                </x-filament::section>
            @endforeach
            <x-filament::section compact>
                <div style="font-size:12px;opacity:.7">{{ __('timeclock.timesheet.totals.absences') }}</div>
                <div style="font-size:20px;font-weight:bold">{{ $totals['absences'] }}</div>
            </x-filament::section>
            @if ($bank !== null)
                <x-filament::section compact>
                    <div style="font-size:12px;opacity:.7">{{ __('timeclock.timesheet.totals.bank') }}</div>
                    <div style="font-size:20px;font-weight:bold;color:{{ $bank['balance'] < 0 ? '#dc2626' : '#15803d' }}">{{ ($bank['balance'] < 0 ? '−' : '+').ScheduleCalendar::hours(abs($bank['balance'])) }}</div>
                    @if ($bank['expired'] > 0)
                        <div style="font-size:12px;color:#b45309">{{ __('timeclock.timesheet.bank_expired', ['hours' => ScheduleCalendar::hours($bank['expired'])]) }}</div>
                    @endif
                </x-filament::section>
            @endif
        </div>

        <x-filament::section>
            {{-- Phones: one card per day (the 9-column table hid the totals and "Ask for a correction" off screen). --}}
            <style>
                .kb-days { display: none }
                @media (max-width: 640px) { .kb-table { display: none } .kb-days { display: flex } }
            </style>
            <div class="kb-days" style="flex-direction:column;gap:8px">
                @foreach ($days as $key => $day)
                    <div style="border:1px solid rgba(127,127,127,.2);border-radius:10px;padding:10px 12px;{{ $day['type'] !== 'work' ? 'background:rgba(127,127,127,.06);' : '' }}{{ ! $day['employed'] ? 'opacity:.4;' : '' }}">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                            <strong style="font-size:14px">{{ ucfirst($day['date']->translatedFormat(__('timeclock.holidays.day_month_weekday_format'))) }}</strong>
                            <span style="font-size:13px">
                                @if ($day['holiday'])
                                    <x-filament::badge color="warning" size="sm">{{ $day['holiday'] }}</x-filament::badge>
                                @elseif ($day['plan'])
                                    {{ $day['plan']['start'] }}–{{ $day['plan']['end'] }}
                                @elseif ($day['type'] === 'off')
                                    <span style="opacity:.7">{{ __('timeclock.employees.day_off') }}</span>
                                @else
                                    <span style="opacity:.7">{{ __('timeclock.employees.no_schedule') }}</span>
                                @endif
                            </span>
                        </div>
                        @if ($day['punches'] !== [] || $day['ignored'] !== [])
                            <div style="margin-top:6px;font-size:15px">
                                @foreach ($day['punches'] as $punch)
                                    <span style="white-space:nowrap;margin-right:10px">{{ $punch['at']->format('H:i') }}@if ($punch['kind'] === 'added')<sup title="{{ __('timeclock.timesheet.added') }}">I</sup>@endif</span>
                                @endforeach
                                @if ($day['ignored'] !== [])
                                    <span style="font-size:12px;opacity:.7">({{ trans_choice('timeclock.timesheet.ignored', count($day['ignored']), ['count' => count($day['ignored'])]) }})</span>
                                @endif
                            </div>
                        @endif
                        @if ($day['counts'] && ($day['type'] === 'work' || $day['worked'] > 0))
                            <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px 14px;font-size:13px">
                                <span>{{ __('timeclock.timesheet.worked') }}: <strong>{{ $h($day['worked']) }}</strong></span>
                                @if ($day['extra'] + $day['extra_rest'] > 0)
                                    <span style="color:#15803d">{{ __('timeclock.timesheet.extra') }}: <strong>{{ $h($day['extra'] + $day['extra_rest']) }}</strong></span>
                                @endif
                                @if ($day['deficit'] > 0)
                                    <span style="color:#dc2626">{{ __('timeclock.timesheet.short') }}: <strong>{{ $h($day['deficit']) }}</strong></span>
                                @endif
                                @if ($day['night_reduced'] > 0)
                                    <span>{{ __('timeclock.timesheet.night') }}: <strong>{{ $h($day['night_reduced']) }}</strong></span>
                                @endif
                            </div>
                        @endif
                        @if ($day['absence'] || $day['flags'] !== [] || $day['adjustments'] !== [] || $day['pending'] > 0)
                            <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px">
                                @if ($day['absence'])
                                    <x-filament::badge color="danger" size="sm">{{ __('timeclock.timesheet.flags.absence') }}</x-filament::badge>
                                @endif
                                @foreach ($day['flags'] as $flag)
                                    <x-filament::badge color="warning" size="sm">{{ \App\Modules\TimeClock\Services\DayCalculator::flagLabel($flag, $day) }}</x-filament::badge>
                                @endforeach
                                @foreach ($day['adjustments'] as $adjustment)
                                    @if ($adjustment->type === Adjustment::EXCUSE)
                                        <x-filament::badge color="info" size="sm">{{ $adjustment->minutes === null ? __('timeclock.timesheet.excused_day') : __('timeclock.timesheet.excused', ['hours' => ScheduleCalendar::hours($adjustment->minutes)]) }}</x-filament::badge>
                                    @endif
                                @endforeach
                                @if ($day['pending'] > 0)
                                    <x-filament::badge color="gray" size="sm">{{ trans_choice('timeclock.timesheet.pending', $day['pending'], ['count' => $day['pending']]) }}</x-filament::badge>
                                @endif
                            </div>
                        @endif
                        @if ($day['employed'] && $day['moment'] !== 'future')
                            <div style="margin-top:8px">{{ ($this->adjustAction)(['date' => $key])->link()->size('sm') }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="kb-table" style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead>
                        <tr style="text-align:left;font-size:12px;opacity:.75">
                            <th style="{{ $cell }}">{{ __('timeclock.timesheet.day') }}</th>
                            <th style="{{ $cell }}">{{ __('timeclock.timesheet.expected') }}</th>
                            <th style="{{ $cell }}">{{ __('timeclock.timesheet.punches') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('timeclock.timesheet.worked') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('timeclock.timesheet.extra') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('timeclock.timesheet.short') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('timeclock.timesheet.night') }}</th>
                            <th style="{{ $cell }}">{{ __('timeclock.timesheet.notes') }}</th>
                            <th style="{{ $cell }}"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($days as $key => $day)
                            <tr style="{{ $day['type'] !== 'work' ? 'background:rgba(127,127,127,.06);' : '' }}{{ ! $day['employed'] ? 'opacity:.4;' : '' }}">
                                <td style="{{ $cell }};white-space:nowrap">{{ ucfirst($day['date']->translatedFormat(__('timeclock.holidays.day_month_weekday_format'))) }}</td>
                                <td style="{{ $cell }};white-space:nowrap">
                                    @if ($day['holiday'])
                                        <x-filament::badge color="warning" size="sm">{{ $day['holiday'] }}</x-filament::badge>
                                    @elseif ($day['plan'])
                                        {{ $day['plan']['start'] }}–{{ $day['plan']['end'] }}
                                    @elseif ($day['type'] === 'off')
                                        <span style="opacity:.7">{{ __('timeclock.employees.day_off') }}</span>
                                    @else
                                        <span style="opacity:.7">{{ __('timeclock.employees.no_schedule') }}</span>
                                    @endif
                                </td>
                                <td style="{{ $cell }}">
                                    @foreach ($day['punches'] as $punch)
                                        <span style="white-space:nowrap;margin-right:6px">{{ $punch['at']->format('H:i') }}@if ($punch['kind'] === 'added')<sup title="{{ __('timeclock.timesheet.added') }}">I</sup>@endif</span>
                                    @endforeach
                                    @if ($day['ignored'] !== [])
                                        <span style="font-size:12px;opacity:.7">({{ trans_choice('timeclock.timesheet.ignored', count($day['ignored']), ['count' => count($day['ignored'])]) }})</span>
                                    @endif
                                </td>
                                <td style="{{ $cell }};text-align:right">{{ $day['counts'] ? $h($day['worked']) : '' }}</td>
                                <td style="{{ $cell }};text-align:right;color:#15803d">{{ $day['counts'] ? $h($day['extra'] + $day['extra_rest']) : '' }}</td>
                                <td style="{{ $cell }};text-align:right;color:#dc2626">{{ $day['counts'] ? $h($day['deficit']) : '' }}</td>
                                <td style="{{ $cell }};text-align:right">{{ $day['counts'] ? $h($day['night_reduced']) : '' }}</td>
                                <td style="{{ $cell }}">
                                    <div style="display:flex;flex-wrap:wrap;gap:4px">
                                        @if ($day['absence'])
                                            <x-filament::badge color="danger" size="sm">{{ __('timeclock.timesheet.flags.absence') }}</x-filament::badge>
                                        @endif
                                        @foreach ($day['flags'] as $flag)
                                            <x-filament::badge color="warning" size="sm">{{ \App\Modules\TimeClock\Services\DayCalculator::flagLabel($flag, $day) }}</x-filament::badge>
                                        @endforeach
                                        @foreach ($day['adjustments'] as $adjustment)
                                            @if ($adjustment->type === Adjustment::EXCUSE)
                                                <x-filament::badge color="info" size="sm">{{ $adjustment->minutes === null ? __('timeclock.timesheet.excused_day') : __('timeclock.timesheet.excused', ['hours' => ScheduleCalendar::hours($adjustment->minutes)]) }}</x-filament::badge>
                                            @endif
                                        @endforeach
                                        @if ($day['pending'] > 0)
                                            <x-filament::badge color="gray" size="sm">{{ trans_choice('timeclock.timesheet.pending', $day['pending'], ['count' => $day['pending']]) }}</x-filament::badge>
                                        @endif
                                    </div>
                                </td>
                                <td style="{{ $cell }}">
                                    @if ($day['employed'] && $day['moment'] !== 'future')
                                        {{ ($this->adjustAction)(['date' => $key]) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p style="margin-top:10px;font-size:12px;opacity:.7">{{ __('timeclock.timesheet.legend') }}</p>
        </x-filament::section>
    @endif

</x-filament-panels::page>
