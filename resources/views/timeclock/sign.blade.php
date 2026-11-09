@php
    use App\Modules\TimeClock\Services\ScheduleCalendar;
    $h = fn (int $minutes): string => $minutes === 0 ? '—' : ScheduleCalendar::hours($minutes);
    $row = 'display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #f0f0f0;text-align:left;font-size:14px';
    $person = $employee->person;
    $tz = \Filament\Support\Facades\FilamentTimezone::get();
@endphp
<x-layout :title="__('timeclock.closing.pdf_title')" :brand="$company" :logo="$logo" :color="$color">
    <h1>{{ __('timeclock.closing.pdf_title') }} · {{ $monthLabel }}</h1>
    <p>{{ $person?->name }}</p>

    @if (session('status'))
        <p style="color:#047857;font-weight:bold">{{ session('status') }}</p>
    @endif

    @if ($closing->reopened_at !== null)
        <p>{{ __('timeclock.sign_link.reopened') }}</p>
    @else
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:16px 0;text-align:center">
            @foreach (['worked' => $totals['worked'] ?? 0, 'extra' => ($totals['extra'] ?? 0) + ($totals['extra_rest'] ?? 0), 'deficit' => ($totals['deficit'] ?? 0) - ($totals['absence_minutes'] ?? 0)] as $key => $minutes)
                <div style="border:1px solid #e5e7eb;border-radius:8px;padding:8px">
                    <div style="font-size:12px;color:#6b7280">{{ __('timeclock.timesheet.totals.'.$key) }}</div>
                    <div style="font-size:17px;font-weight:bold">{{ $h($minutes) }}</div>
                </div>
            @endforeach
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:8px">
                <div style="font-size:12px;color:#6b7280">{{ __('timeclock.timesheet.totals.absences') }}</div>
                <div style="font-size:17px;font-weight:bold">{{ $totals['absences'] ?? 0 }}</div>
            </div>
        </div>

        <details style="text-align:left;margin-bottom:8px">
            <summary style="cursor:pointer;font-size:14px;font-weight:bold">{{ __('timeclock.sign_link.days') }}</summary>
            @foreach ($days as $day)
                @continue(! $day['counts'] && $day['punches'] === [])
                <div style="{{ $row }}">
                    <div>
                        <strong>{{ $day['date']->translatedFormat('D d/m') }}</strong>
                        <div style="color:#6b7280">
                            {{ collect($day['punches'])->map(fn (array $punch): string => $punch['at']->format('H:i'))->implode(' · ') ?: ($day['absence'] ? __('timeclock.timesheet.totals.absences') : '—') }}
                        </div>
                    </div>
                    <div style="text-align:right;white-space:nowrap">
                        {{ $h($day['worked']) }}
                        @if ($day['extra'] + $day['extra_rest'] > 0)<div style="color:#047857">+{{ $h($day['extra'] + $day['extra_rest']) }}</div>@endif
                        @if ($day['deficit'] > 0 && ! $day['absence'])<div style="color:#b91c1c">−{{ $h($day['deficit']) }}</div>@endif
                    </div>
                </div>
            @endforeach
        </details>

        <a class="button secondary" href="{{ route('timeclock.sign.pdf', ['token' => $closing->sign_token]) }}">{{ __('timeclock.closing.pdf') }}</a>

        @if ($closing->signed_at)
            <p style="margin-top:20px;color:#047857;font-weight:bold">{{ __('timeclock.closing.signed_on', ['date' => $closing->signed_at->setTimezone($tz)->translatedFormat(__('app.datetime_format'))]) }}</p>
        @elseif ($closing->disputed_at)
            <p style="margin-top:20px">{{ __('timeclock.closing.disputed_on', ['date' => $closing->disputed_at->setTimezone($tz)->translatedFormat(__('app.datetime_format'))]) }} {{ $closing->dispute_reason }}</p>
        @else
            <p style="margin-top:20px">{{ __('timeclock.closing.waiting_you') }}</p>

            @if ($errors->any())
                <p style="color:#b91c1c;font-weight:bold">{{ $errors->first() }}</p>
            @endif

            <form method="POST" action="{{ route('timeclock.sign.answer', ['token' => $closing->sign_token]) }}" style="text-align:left">
                @csrf
                @if ($identity)
                    <label for="identity">{{ __('timeclock.sign_link.identity_'.$identity) }}</label>
                    <input id="identity" name="identity" inputmode="numeric" autocomplete="off" maxlength="{{ $identity === 'pin' ? 6 : 4 }}" required
                        style="width:100%;padding:11px 12px;font-size:16px;border:1px solid #d1d5db;border-radius:8px;letter-spacing:4px">
                    <div class="muted" style="color:#6b7280;margin-top:4px">{{ __('timeclock.sign_link.identity_'.$identity.'_help') }}</div>
                @endif

                <label style="font-weight:normal;display:flex;gap:8px;align-items:flex-start;margin-top:16px">
                    <input type="checkbox" name="agree" value="1" style="margin-top:3px"> {{ __('timeclock.closing.sign_agree') }}
                </label>
                <button type="submit" name="answer" value="sign">{{ __('timeclock.closing.sign') }}</button>

                <details style="margin-top:16px">
                    <summary style="cursor:pointer;font-size:14px;color:#6b7280">{{ __('timeclock.closing.dispute') }}</summary>
                    <label for="reason">{{ __('timeclock.closing.dispute_reason') }}</label>
                    <textarea id="reason" name="reason" rows="3" style="width:100%;padding:10px;font-size:15px;border:1px solid #d1d5db;border-radius:8px">{{ old('reason') }}</textarea>
                    <div class="muted" style="color:#6b7280;margin-top:4px">{{ __('timeclock.closing.dispute_help') }}</div>
                    <button type="submit" name="answer" value="dispute" class="button secondary">{{ __('timeclock.sign_link.send_dispute') }}</button>
                </details>
            </form>
            <p class="muted" style="margin-top:16px">{{ __('timeclock.sign_link.legal') }}</p>
        @endif
    @endif
</x-layout>
