<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\EmployeeSchedule;
use App\Modules\TimeClock\Models\Schedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What each schedule expects on each day: the hours, the break and the minutes of work — or a day off.
 * For an employee it also follows their schedule history and the holidays of their workplace
 * (a holiday is a day off; working on it is counted in part 4).
 */
class ScheduleCalendar
{
    /**
     * The plan of a schedule for a day, or null when it is a day off.
     *
     * @return array{start: string, end: string, break_start: ?string, break_end: ?string, break_minutes: int, minutes: int}|null
     */
    public function dayPlan(Schedule $schedule, CarbonInterface $date): ?array
    {
        if ($schedule->type === Schedule::CYCLE) {
            if (! $schedule->anchor_date || ! $schedule->work_days) {
                return null;
            }

            $length = $schedule->work_days + (int) $schedule->off_days;
            $days = (int) CarbonImmutable::parse($schedule->anchor_date->toDateString())->diffInDays(CarbonImmutable::parse($date->toDateString()), false);
            $position = (($days % $length) + $length) % $length;

            if ($position >= $schedule->work_days) {
                return null;
            }

            $break = (int) $schedule->break_minutes;

            return [
                'start' => (string) $schedule->start_time,
                'end' => (string) $schedule->end_time,
                'break_start' => null,
                'break_end' => null,
                'break_minutes' => $break,
                'minutes' => max(0, static::span((string) $schedule->start_time, (string) $schedule->end_time) - $break),
            ];
        }

        $day = ((array) $schedule->week)[(string) $date->dayOfWeekIso] ?? null;

        if (! is_array($day) || ! ($day['works'] ?? false) || blank($day['start'] ?? null) || blank($day['end'] ?? null)) {
            return null;
        }

        $break = filled($day['break_start'] ?? null) && filled($day['break_end'] ?? null) ? static::span($day['break_start'], $day['break_end']) : 0;

        return [
            'start' => $day['start'],
            'end' => $day['end'],
            'break_start' => $day['break_start'] ?? null,
            'break_end' => $day['break_end'] ?? null,
            'break_minutes' => $break,
            'minutes' => max(0, static::span($day['start'], $day['end']) - $break),
        ];
    }

    /** Minutes between two times of the day; an end before (or equal to) the start is on the next day. */
    public static function span(string $start, string $end): int
    {
        $minutes = static::minutesOf($end) - static::minutesOf($start);

        return $minutes <= 0 ? $minutes + 1440 : $minutes;
    }

    public static function minutesOf(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)) + [0, 0]);

        return $hours * 60 + $minutes;
    }

    /** "44:00", "7:20". */
    public static function hours(int $minutes): string
    {
        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** Average minutes of work per week (a cycle like 12x36 averages 42 hours). */
    public function weeklyMinutes(Schedule $schedule): int
    {
        if ($schedule->type === Schedule::CYCLE) {
            $length = (int) $schedule->work_days + (int) $schedule->off_days;
            $plan = $this->dayPlan($schedule, $schedule->anchor_date ?? CarbonImmutable::today());

            return $length > 0 && $plan !== null ? (int) round($plan['minutes'] * $schedule->work_days / $length * 7) : 0;
        }

        $total = 0;
        $monday = CarbonImmutable::today()->startOfWeek();

        for ($day = 0; $day < 7; $day++) {
            $total += $this->dayPlan($schedule, $monday->addDays($day))['minutes'] ?? 0;
        }

        return $total;
    }

    /** One line for the lists, e.g. "Mon–Fri 08:00–18:00" or "12x36 · 07:00–19:00". */
    public function summary(Schedule $schedule): string
    {
        if ($schedule->type === Schedule::CYCLE) {
            return __('timeclock.schedules.summary_cycle', [
                'work' => $schedule->work_days,
                'off' => $schedule->off_days,
                'start' => $schedule->start_time,
                'end' => $schedule->end_time,
            ]);
        }

        $parts = collect(range(1, 7))
            ->map(function (int $weekday) use ($schedule): ?string {
                $day = ((array) $schedule->week)[(string) $weekday] ?? null;

                return is_array($day) && ($day['works'] ?? false) ? __('timeclock.weekdays_short.'.$weekday).' '.$day['start'].'–'.$day['end'] : null;
            })
            ->filter();

        return $parts->isEmpty() ? __('timeclock.schedules.no_days') : $parts->implode(' · ');
    }

    /** The schedule the employee follows on a day (their history; null before the first one). */
    public function scheduleOn(Employee $employee, CarbonInterface $date): ?Schedule
    {
        return EmployeeSchedule::query()
            ->with('schedule')
            ->where('employee_id', $employee->getKey())
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->orderByDesc('starts_on')
            ->first()
            ?->schedule;
    }

    /**
     * The days of a period for an employee: the plan of the day, or a day off, or a holiday.
     *
     * @return array<string, array{date: CarbonImmutable, plan: ?array, holiday: ?string, schedule: ?Schedule}>
     */
    public function period(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $holidays = app(HolidayCalendar::class)->between($from, $to, $employee->workplace_id);
        $days = [];

        for ($date = CarbonImmutable::parse($from->toDateString()); $date->lte($to); $date = $date->addDay()) {
            $schedule = $this->scheduleOn($employee, $date);
            $holiday = $holidays[$date->toDateString()] ?? null;

            $days[$date->toDateString()] = [
                'date' => $date,
                'plan' => $schedule && $holiday === null ? $this->dayPlan($schedule, $date) : null,
                'holiday' => $holiday,
                'schedule' => $schedule,
            ];
        }

        return $days;
    }
}
