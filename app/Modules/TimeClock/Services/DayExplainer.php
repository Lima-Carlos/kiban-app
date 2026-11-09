<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\TimeClockSetting;
use Carbon\CarbonImmutable;

/**
 * "Why did it come out like this?": the calculation of one day of the time sheet in plain sentences (expected,
 * worked pairs, tolerance, overtime, short time, absence, night hours, corrections and warnings). The biggest
 * complaint about time-clock systems is "the system calculated it wrong"; this shows the reason, day by day.
 */
class DayExplainer
{
    /**
     * @param  array<string, mixed>  $day  one day of DayCalculator::period() (or of a frozen closing)
     * @return array<int, string>
     */
    public function explain(array $day): array
    {
        $settings = TimeClockSetting::current();
        $h = fn (int $minutes): string => ScheduleCalendar::hours($minutes);
        $lines = [];

        if (! ($day['employed'] ?? true)) {
            return [__('timeclock.why.not_employed')];
        }

        if (($day['moment'] ?? 'past') === 'future') {
            return [__('timeclock.why.future')];
        }

        $plan = $day['plan'] ?? null;

        // What was expected.
        $lines[] = match ($day['type'] ?? 'work') {
            'holiday' => __('timeclock.why.holiday', ['name' => (string) $day['holiday']]),
            'off' => __('timeclock.why.off'),
            'no_schedule' => __('timeclock.why.no_schedule'),
            default => filled($plan['break_start'] ?? null)
                ? __('timeclock.why.expected_break', ['start' => $plan['start'], 'end' => $plan['end'], 'break_start' => $plan['break_start'], 'break_end' => $plan['break_end'], 'hours' => $h((int) $plan['minutes'])])
                : __('timeclock.why.expected', ['start' => $plan['start'] ?? '', 'end' => $plan['end'] ?? '', 'hours' => $h((int) ($plan['minutes'] ?? 0))]),
        };

        // The punches, in pairs, and what each pair counted.
        $punches = array_values($day['punches'] ?? []);
        $pairs = [];

        foreach (array_chunk($punches, 2) as $pair) {
            if (count($pair) === 2) {
                $in = CarbonImmutable::parse($pair[0]['at']);
                $out = CarbonImmutable::parse($pair[1]['at']);
                $pairs[] = $in->format('H:i').'–'.$out->format('H:i').' ('.$h(intdiv((int) $in->diffInSeconds($out, true), 60)).')';
            }
        }

        if ($punches === []) {
            $lines[] = __('timeclock.why.no_punches');
        } else {
            $lines[] = __('timeclock.why.worked', ['pairs' => implode(', ', $pairs) ?: '—', 'hours' => $h((int) $day['worked'])]);
        }

        if (count($punches) % 2 === 1) {
            $lines[] = __('timeclock.why.odd', ['time' => CarbonImmutable::parse(end($punches)['at'])->format('H:i')]);
        }

        // Corrections.
        foreach ($day['adjustments'] ?? [] as $adjustment) {
            $type = is_array($adjustment) ? ($adjustment['type'] ?? null) : $adjustment->type;
            $reason = (string) (is_array($adjustment) ? ($adjustment['reason'] ?? '') : $adjustment->reason);
            $minutes = is_array($adjustment) ? ($adjustment['minutes'] ?? null) : $adjustment->minutes;
            $at = is_array($adjustment) ? ($adjustment['punch_at'] ?? null) : $adjustment->punch_at;

            $lines[] = match ($type) {
                Adjustment::ADD_PUNCH => __('timeclock.why.added', ['time' => $at ? CarbonImmutable::parse($at)->setTimezone(CarbonImmutable::parse($day['date'])->getTimezone())->format('H:i') : '', 'reason' => $reason]),
                Adjustment::IGNORE_PUNCH => __('timeclock.why.ignored', ['reason' => $reason]),
                Adjustment::EXCUSE => $minutes === null ? __('timeclock.why.excused_day', ['reason' => $reason]) : __('timeclock.why.excused', ['hours' => $h((int) $minutes), 'reason' => $reason]),
                default => '',
            };
        }

        // The result.
        if (($day['type'] ?? 'work') === 'work') {
            if ($day['absence'] ?? false) {
                $lines[] = __('timeclock.why.absence');
            } elseif ($day['tolerance'] ?? false) {
                $lines[] = __('timeclock.why.tolerance', ['punch' => $settings->tolerance_punch_minutes, 'day' => $settings->tolerance_day_minutes]);
            }

            if (($day['extra'] ?? 0) > 0) {
                $lines[] = $settings->hour_bank
                    ? __('timeclock.why.extra_bank', ['hours' => $h((int) $day['extra'])])
                    : __('timeclock.why.extra', ['hours' => $h((int) $day['extra']), 'percent' => $settings->overtime_percent]);
            }

            if (($day['deficit'] ?? 0) > 0 && ! ($day['absence'] ?? false)) {
                $lines[] = $settings->hour_bank
                    ? __('timeclock.why.short_bank', ['hours' => $h((int) $day['deficit'])])
                    : __('timeclock.why.short', ['hours' => $h((int) $day['deficit'])]);
            }

            if (($day['extra'] ?? 0) === 0 && ($day['deficit'] ?? 0) === 0 && ! ($day['absence'] ?? false) && $punches !== []) {
                $lines[] = __('timeclock.why.exact');
            }
        } elseif (($day['extra_rest'] ?? 0) > 0) {
            $lines[] = __('timeclock.why.extra_rest', ['hours' => $h((int) $day['extra_rest']), 'percent' => $settings->rest_day_overtime_percent]);
        }

        if (($day['night'] ?? 0) > 0) {
            $lines[] = $settings->night_reduced_hour
                ? __('timeclock.why.night_reduced', ['hours' => $h((int) $day['night']), 'start' => substr((string) $settings->night_start, 0, 5), 'end' => substr((string) $settings->night_end, 0, 5), 'reduced' => $h((int) $day['night_reduced']), 'percent' => $settings->night_percent])
                : __('timeclock.why.night', ['hours' => $h((int) $day['night']), 'start' => substr((string) $settings->night_start, 0, 5), 'end' => substr((string) $settings->night_end, 0, 5), 'percent' => $settings->night_percent]);
        }

        // Legal warnings (they never change the hours).
        foreach ($day['flags'] ?? [] as $flag) {
            if ($flag !== 'missing_punch') {
                $lines[] = __('timeclock.why.warning', ['text' => DayCalculator::flagLabel($flag, $day)]);
            }
        }

        if (($day['pending'] ?? 0) > 0) {
            $lines[] = __('timeclock.why.pending');
        }

        return array_values(array_filter($lines));
    }
}
