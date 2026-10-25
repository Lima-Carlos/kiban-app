@php
    use App\Modules\TimeClock\Models\Adjustment;
    use App\Modules\TimeClock\Services\ScheduleCalendar;
    $h = fn (int $minutes): string => $minutes === 0 ? '' : ScheduleCalendar::hours($minutes);
    $person = $employee->person;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('timeclock.closing.pdf_title') }}</title>
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        @page { margin: 22px 26px; }
        body { font-size: 8px; color: #39383A; margin: 0; }
        h1 { font-size: 13px; color: #044299; margin: 0; }
        .muted { color: #777; }
        .head { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .head td { vertical-align: top; padding: 1px 4px 1px 0; }
        .preview { background: #fff4d6; border: 1px solid #e0b84c; padding: 4px 6px; margin-bottom: 6px; }
        table.days { width: 100%; border-collapse: collapse; }
        table.days th { background: #044299; color: #fff; text-align: left; padding: 3px 4px; font-weight: bold; }
        table.days td { border-bottom: 1px solid #ddd; padding: 2px 4px; vertical-align: top; }
        table.days .off td { background: #f3f3f3; }
        .num { text-align: right; white-space: nowrap; }
        .totals { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .totals td { border: 1px solid #ccc; padding: 3px 5px; }
        .totals .label { color: #777; font-size: 7px; }
        .totals .value { font-size: 10px; font-weight: bold; }
        .sign { width: 100%; margin-top: 26px; }
        .sign td { width: 50%; text-align: center; padding: 0 30px; }
        .line { border-top: 1px solid #39383A; padding-top: 2px; }
        .code { font-family: "DejaVu Sans Mono", monospace; font-size: 7px; }
    </style>
</head>
<body>
    <h1>{{ __('timeclock.closing.pdf_title') }} · {{ $monthLabel }}</h1>
    <table class="head">
        <tr>
            <td style="width:50%">
                <strong>{{ $company }}</strong>@if ($employerDocument) · {{ $employerDocument }}@endif<br>
                @if ($employee->workplace)<span class="muted">{{ __('timeclock.fields.workplace') }}:</span> {{ $employee->workplace->name }}<br>@endif
            </td>
            <td>
                <strong>{{ $person?->name }}</strong>@if ($person?->documentLabel()) · {{ $person->documentLabel() }}@endif<br>
                @if ($employee->registration)<span class="muted">{{ __('timeclock.fields.registration') }}:</span> {{ $employee->registration }} &nbsp; @endif
                @if ($employee->pis)<span class="muted">{{ __('timeclock.fields.pis') }}:</span> {{ $employee->pis }} &nbsp; @endif
                @if ($employee->job_title)<span class="muted">{{ __('timeclock.fields.job_title') }}:</span> {{ $employee->job_title }} &nbsp; @endif
                @if ($employee->admitted_on)<span class="muted">{{ __('timeclock.fields.admitted_on') }}:</span> {{ $employee->admitted_on->translatedFormat(__('app.date_format')) }}@endif
            </td>
        </tr>
    </table>

    @if ($closing === null)
        <div class="preview">{{ __('timeclock.closing.pdf_preview') }}</div>
    @endif

    <table class="days">
        <thead>
            <tr>
                <th>{{ __('timeclock.timesheet.day') }}</th>
                <th>{{ __('timeclock.timesheet.expected') }}</th>
                <th>{{ __('timeclock.timesheet.punches') }}</th>
                <th class="num">{{ __('timeclock.timesheet.worked') }}</th>
                <th class="num">{{ __('timeclock.timesheet.extra') }}</th>
                <th class="num">{{ __('timeclock.timesheet.short') }}</th>
                <th class="num">{{ __('timeclock.timesheet.night') }}</th>
                <th>{{ __('timeclock.timesheet.notes') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($days as $day)
                @continue (! $day['employed'])
                <tr class="{{ $day['type'] !== 'work' ? 'off' : '' }}">
                    <td style="white-space:nowrap">{{ ucfirst($day['date']->translatedFormat(__('timeclock.holidays.day_month_weekday_format'))) }}</td>
                    <td style="white-space:nowrap">
                        @if ($day['holiday']) {{ $day['holiday'] }}
                        @elseif ($day['plan']) {{ $day['plan']['start'] }}–{{ $day['plan']['end'] }}
                        @elseif ($day['type'] === 'off') {{ __('timeclock.employees.day_off') }}
                        @else <span class="muted">{{ __('timeclock.employees.no_schedule') }}</span>
                        @endif
                    </td>
                    <td>@foreach ($day['punches'] as $punch){{ $punch['at']->format('H:i') }}@if ($punch['kind'] === 'added')(I)@endif @endforeach</td>
                    <td class="num">{{ $day['counts'] ? $h($day['worked']) : '' }}</td>
                    <td class="num">{{ $day['counts'] ? $h($day['extra'] + $day['extra_rest']) : '' }}</td>
                    <td class="num">{{ $day['counts'] ? $h($day['deficit']) : '' }}</td>
                    <td class="num">{{ $day['counts'] ? $h($day['night_reduced']) : '' }}</td>
                    <td>
                        {{ collect([
                            $day['absence'] ? __('timeclock.timesheet.flags.absence') : null,
                            ...array_map(fn (string $flag): string => __('timeclock.timesheet.flags.'.$flag), $day['flags']),
                            ...collect($day['adjustments'])->where('type', Adjustment::EXCUSE)->map(fn ($excuse): string => $excuse->minutes === null ? __('timeclock.timesheet.excused_day') : __('timeclock.timesheet.excused', ['hours' => ScheduleCalendar::hours($excuse->minutes)]))->all(),
                            count($day['ignored']) > 0 ? trans_choice('timeclock.timesheet.ignored', count($day['ignored']), ['count' => count($day['ignored'])]) : null,
                        ])->filter()->implode(' · ') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            @foreach (['expected' => $totals['expected'], 'worked' => $totals['worked'], 'extra' => $totals['extra'], 'extra_rest' => $totals['extra_rest'], 'deficit' => $totals['deficit'] - $totals['absence_minutes'], 'night' => $totals['night_reduced']] as $key => $minutes)
                <td><div class="label">{{ __('timeclock.timesheet.totals.'.$key) }}</div><div class="value">{{ ScheduleCalendar::hours($minutes) }}</div></td>
            @endforeach
            <td><div class="label">{{ __('timeclock.timesheet.totals.absences') }}</div><div class="value">{{ $totals['absences'] }}</div></td>
            @if ($bank !== null)
                <td><div class="label">{{ __('timeclock.timesheet.totals.bank') }}</div><div class="value">{{ ($bank['balance'] < 0 ? '−' : '+').ScheduleCalendar::hours(abs($bank['balance'])) }}</div></td>
            @endif
        </tr>
    </table>
    <p class="muted">{{ __('timeclock.timesheet.legend') }} {{ __('timeclock.closing.pdf_percentages', ['overtime' => $settings->overtime_percent, 'rest' => $settings->rest_day_overtime_percent, 'night' => $settings->night_percent]) }}</p>

    @if ($closing !== null)
        <p>
            {{ __('timeclock.closing.pdf_closed', ['date' => $closing->closed_at->timezone($generatedAt->getTimezone())->translatedFormat(__('app.datetime_format')), 'name' => $closing->closer?->name ?? '—']) }}<br>
            @if ($closing->signed_at)
                <strong>{{ __('timeclock.closing.pdf_signed', ['name' => $closing->signer?->name ?? $person?->name, 'date' => $closing->signed_at->timezone($generatedAt->getTimezone())->translatedFormat(__('app.datetime_format')), 'ip' => $closing->signature_ip ?? '—']) }}</strong><br>
                <span class="code">{{ __('timeclock.closing.pdf_signature_code') }}: {{ $closing->signature_hash }}</span><br>
            @elseif ($closing->disputed_at)
                <strong>{{ __('timeclock.closing.pdf_disputed', ['date' => $closing->disputed_at->timezone($generatedAt->getTimezone())->translatedFormat(__('app.date_format'))]) }}</strong> {{ $closing->dispute_reason }}<br>
            @endif
            <span class="code">{{ __('timeclock.closing.pdf_snapshot_code') }}: {{ $closing->snapshot_hash }}</span>
        </p>
    @endif

    @if ($closing === null || ! $closing->signed_at)
        <table class="sign">
            <tr>
                <td><div class="line">{{ $person?->name }}</div></td>
                <td><div class="line">{{ $company }}</div></td>
            </tr>
        </table>
    @endif

    <p class="muted" style="margin-top:8px">{{ __('timeclock.closing.pdf_generated', ['date' => $generatedAt->translatedFormat(__('app.datetime_format'))]) }} · Kiban Ponto</p>
</body>
</html>
