<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Models\TimeOff;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Services\ScheduleCalendar;
use App\Modules\TimeClock\TimeClockModule;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * When a professional attends on a day: the working time minus the break and the time off.
 *
 * - With the Ponto (and "follow the Ponto" on), a professional who is an employee with a work schedule on that day
 *   follows that schedule, holidays included (the same ScheduleCalendar the time clock uses: one truth);
 * - otherwise, the professional's own weekly hours, kept in the same shape as the Ponto schedules.
 * Times are in the company's time zone; a shift ending before it starts ends on the next day.
 */
class WorkingHours
{
    public function __construct(protected ScheduleCalendar $calendar, protected TenantContext $context) {}

    /**
     * The free-to-book pieces of a day, in order.
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function intervals(Professional $professional, CarbonImmutable $date): array
    {
        $day = $this->day($professional, $date);

        if ($day['plan'] === null) {
            return [];
        }

        $plan = $day['plan'];
        $local = CarbonImmutable::parse($date->toDateString(), AgendaSetting::timezone());
        $start = $this->at($local, $plan['start']);
        $end = $this->at($local, $plan['end']);
        $end = $end->lte($start) ? $end->addDay() : $end;
        $pieces = [[$start, $end]];

        if (filled($plan['break_start'] ?? null) && filled($plan['break_end'] ?? null)) {
            $breakStart = $this->at($local, $plan['break_start']);
            $breakStart = $breakStart->lt($start) ? $breakStart->addDay() : $breakStart;
            $breakEnd = $this->at($local, $plan['break_end']);
            $breakEnd = $breakEnd->lte($breakStart) ? $breakEnd->addDay() : $breakEnd;
            $pieces = $this->subtract($pieces, $breakStart, $breakEnd);
        }

        $off = TimeOff::query()->where('professional_id', $professional->getKey())
            ->where('starts_at', '<', $end->utc())
            ->where('ends_at', '>', $start->utc())
            ->get();

        foreach ($off as $timeOff) {
            $pieces = $this->subtract($pieces, $timeOff->starts_at->setTimezone($local->getTimezone()), $timeOff->ends_at->setTimezone($local->getTimezone()));
        }

        return $pieces;
    }

    /**
     * The plan of the day and where it came from ("time_clock" or "own"), with the holiday when there is one.
     *
     * @return array{plan: ?array<string, mixed>, source: string, holiday: mixed}
     */
    public function day(Professional $professional, CarbonImmutable $date): array
    {
        $employee = $this->employee($professional);

        if ($employee !== null && $this->calendar->scheduleOn($employee, $date) !== null) {
            $day = $this->calendar->period($employee, $date, $date)[$date->toDateString()] ?? null;

            return ['plan' => $day['plan'] ?? null, 'source' => 'time_clock', 'holiday' => $day['holiday'] ?? null];
        }

        return ['plan' => $this->calendar->dayPlan($this->ownSchedule($professional), $date), 'source' => 'own', 'holiday' => null];
    }

    /** The Ponto employee the hours come from, or null (no Ponto, not following it, not an employee). */
    public function employee(Professional $professional): ?Employee
    {
        $tenant = $this->context->current();

        if (! $professional->follows_time_clock || $tenant === null || ! $tenant->license()->hasModule(TimeClockModule::KEY)) {
            return null;
        }

        return Employee::query()->active()->where('person_id', $professional->person_id)->first();
    }

    /** The professional's own hours as a (never saved) weekly schedule, so the Ponto's rules read them. */
    public function ownSchedule(Professional $professional): Schedule
    {
        return new Schedule(['type' => Schedule::WEEKLY, 'week' => (array) $professional->week]);
    }

    /**
     * A usual week to start from: Monday to Friday 08:00–18:00 with lunch, Saturday 08:00–13:00.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaultWeek(): array
    {
        $week = [];

        foreach (range(1, 7) as $weekday) {
            $week[(string) $weekday] = match (true) {
                $weekday <= 5 => ['works' => true, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                $weekday === 6 => ['works' => true, 'start' => '08:00', 'end' => '13:00', 'break_start' => null, 'break_end' => null],
                default => ['works' => false, 'start' => null, 'end' => null, 'break_start' => null, 'break_end' => null],
            };
        }

        return $week;
    }

    protected function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)) + [0, 0]);

        return $day->setTime($hours, $minutes);
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $pieces
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    protected function subtract(array $pieces, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = [];

        foreach ($pieces as [$start, $end]) {
            if ($to->lte($start) || $from->gte($end)) {
                $result[] = [$start, $end];

                continue;
            }

            if ($from->gt($start)) {
                $result[] = [$start, $from];
            }

            if ($to->lt($end)) {
                $result[] = [$to, $end];
            }
        }

        return $result;
    }
}
