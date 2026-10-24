<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\EmployeeSchedule;
use App\Modules\TimeClock\Models\Schedule;
use App\People\PersonRuleException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Work schedules and who follows which:
 * - ready models to start from (office hours, 12x36, 6x1...);
 * - a schedule already given to someone is not changed (it would change past days): it is duplicated;
 * - an employee changes schedule from a date; the days before keep the old one.
 */
class ScheduleService
{
    /**
     * Ready models (the form fills itself; everything can be adjusted).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function presets(): array
    {
        $day = fn (string $start, ?string $breakStart, ?string $breakEnd, string $end): array => ['works' => true, 'start' => $start, 'break_start' => $breakStart, 'break_end' => $breakEnd, 'end' => $end];
        $off = ['works' => false, 'start' => null, 'break_start' => null, 'break_end' => null, 'end' => null];
        $weekdays = fn (array $workday, ?array $saturday = null) => [
            '1' => $workday, '2' => $workday, '3' => $workday, '4' => $workday, '5' => $workday,
            '6' => $saturday ?? $off, '7' => $off,
        ];

        return [
            'office' => ['type' => Schedule::WEEKLY, 'week' => $weekdays($day('08:00', '12:00', '13:00', '18:00'))],
            'office_saturday' => ['type' => Schedule::WEEKLY, 'week' => $weekdays($day('08:00', '12:00', '13:00', '17:00'), $day('08:00', null, null, '12:00'))],
            'night_shift' => ['type' => Schedule::WEEKLY, 'week' => $weekdays($day('22:00', '02:00', '03:00', '06:00'))],
            '12x36_day' => ['type' => Schedule::CYCLE, 'work_days' => 1, 'off_days' => 1, 'start_time' => '07:00', 'end_time' => '19:00', 'break_minutes' => 60],
            '12x36_night' => ['type' => Schedule::CYCLE, 'work_days' => 1, 'off_days' => 1, 'start_time' => '19:00', 'end_time' => '07:00', 'break_minutes' => 60],
            '6x1' => ['type' => Schedule::CYCLE, 'work_days' => 6, 'off_days' => 1, 'start_time' => '08:00', 'end_time' => '16:20', 'break_minutes' => 60],
        ];
    }

    /**
     * Checks the hours of a schedule before saving.
     *
     * @param  array<string, mixed>  $data
     */
    public function check(array $data): void
    {
        if (($data['type'] ?? null) === Schedule::CYCLE) {
            foreach (['work_days', 'off_days', 'start_time', 'end_time', 'anchor_date'] as $field) {
                if (blank($data[$field] ?? null)) {
                    throw new PersonRuleException(__('timeclock.errors.schedule_incomplete'));
                }
            }

            if ((int) ($data['break_minutes'] ?? 0) >= ScheduleCalendar::span($data['start_time'], $data['end_time'])) {
                throw new PersonRuleException(__('timeclock.errors.break_too_long'));
            }

            return;
        }

        $works = 0;

        foreach ((array) ($data['week'] ?? []) as $weekday => $day) {
            if (! ($day['works'] ?? false)) {
                continue;
            }

            $works++;

            if (blank($day['start'] ?? null) || blank($day['end'] ?? null)) {
                throw new PersonRuleException(__('timeclock.errors.day_incomplete', ['day' => __('timeclock.weekdays.'.$weekday)]));
            }

            if (filled($day['break_start'] ?? null) !== filled($day['break_end'] ?? null)) {
                throw new PersonRuleException(__('timeclock.errors.break_incomplete', ['day' => __('timeclock.weekdays.'.$weekday)]));
            }

            // The break must be inside the shift (the shift may cross midnight).
            if (filled($day['break_start'] ?? null)) {
                $shift = ScheduleCalendar::span($day['start'], $day['end']);
                $breakStart = ScheduleCalendar::span($day['start'], $day['break_start']);
                $breakEnd = ScheduleCalendar::span($day['start'], $day['break_end']);

                if ($breakStart >= $shift || $breakEnd > $shift || $breakEnd <= $breakStart) {
                    throw new PersonRuleException(__('timeclock.errors.break_outside', ['day' => __('timeclock.weekdays.'.$weekday)]));
                }
            }
        }

        if ($works === 0) {
            throw new PersonRuleException(__('timeclock.errors.no_workday'));
        }
    }

    /** A copy to adjust (the original stays for the days it was used). */
    public function duplicate(Schedule $schedule): Schedule
    {
        $copy = $schedule->replicate(['tenant_id']);
        $copy->name = __('timeclock.schedules.copy_of', ['name' => $schedule->name]);
        $copy->save();

        return $copy;
    }

    /**
     * The employee follows this schedule from this day on. The same day again only switches the schedule
     * of that day (a correction); days before keep what they had.
     */
    public function assign(Employee $employee, Schedule $schedule, CarbonInterface $from): void
    {
        if ($employee->admitted_on && $from->lt($employee->admitted_on)) {
            throw new PersonRuleException(__('timeclock.errors.schedule_before_admission'));
        }

        $current = app(ScheduleCalendar::class)->scheduleOn($employee, $from);

        if ($current?->is($schedule)) {
            return;
        }

        EmployeeSchedule::query()->updateOrCreate(
            ['employee_id' => $employee->getKey(), 'starts_on' => CarbonImmutable::parse($from->toDateString())->toDateString()],
            ['schedule_id' => $schedule->getKey()],
        );
    }

    /** The schedule of today (or the next one, for someone not started yet), to show on the form. */
    public function current(Employee $employee): ?EmployeeSchedule
    {
        $assignments = EmployeeSchedule::query()->where('employee_id', $employee->getKey())->orderByDesc('starts_on')->get();

        return $assignments->first(fn (EmployeeSchedule $assignment): bool => $assignment->starts_on->lte(today())) ?? $assignments->last();
    }
}
