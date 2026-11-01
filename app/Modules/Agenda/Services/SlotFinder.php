<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Free times to book: the professional's working pieces of the day (WorkingHours: hours, break, time off) minus the
 * appointments that hold time, every "slot_minutes" from the start of each piece, long enough for the services.
 * The public page only offers these; the front desk may squeeze an appointment in (a clash is only warned).
 */
class SlotFinder
{
    public function __construct(protected WorkingHours $hours) {}

    /**
     * Minutes the services take for this size (the pick-up takes no time of the professional).
     *
     * @param  iterable<Service>  $services
     */
    public function minutes(iterable $services, ?ItemSize $size): int
    {
        return (int) collect($services)->sum(fn (Service $service): int => $service->is_pickup ? 0 : $service->durationFor($size));
    }

    /**
     * Start times (company time zone) a professional can take on a day.
     *
     * @return array<int, CarbonImmutable>
     */
    public function slots(Professional $professional, CarbonImmutable $date, int $minutes, ?CarbonImmutable $notBefore = null, ?int $ignore = null): array
    {
        $minutes = max(1, $minutes);
        $step = max(5, AgendaSetting::current()->slot_minutes);
        $pieces = $this->hours->intervals($professional, $date);

        if ($pieces === []) {
            return [];
        }

        $busy = $this->busy($professional, $pieces[0][0], end($pieces)[1], $ignore);
        $slots = [];

        foreach ($pieces as [$start, $end]) {
            for ($time = $start; $time->addMinutes($minutes)->lte($end); $time = $time->addMinutes($step)) {
                if ($notBefore !== null && $time->lt($notBefore)) {
                    continue;
                }

                if (! $this->overlaps($busy, $time, $time->addMinutes($minutes))) {
                    $slots[] = $time;
                }
            }
        }

        return $slots;
    }

    /**
     * Free times of any of these professionals: each time with the first professional who has it (the one with
     * fewer appointments that day first, so the work is shared).
     *
     * @param  Collection<int, Professional>  $professionals
     * @return array<string, array{time: CarbonImmutable, professional: Professional}> by "H:i"
     */
    public function anyOf(Collection $professionals, CarbonImmutable $date, int $minutes, ?CarbonImmutable $notBefore = null): array
    {
        $local = CarbonImmutable::parse($date->toDateString(), AgendaSetting::timezone());
        $load = Appointment::query()->holding()
            ->whereIn('professional_id', $professionals->modelKeys())
            ->whereBetween('starts_at', [$local->startOfDay()->utc(), $local->endOfDay()->utc()])
            ->selectRaw('professional_id, count(*) as total')->groupBy('professional_id')->pluck('total', 'professional_id');

        $result = [];

        foreach ($professionals->sortBy(fn (Professional $professional): int => (int) $load->get($professional->getKey(), 0)) as $professional) {
            foreach ($this->slots($professional, $date, $minutes, $notBefore) as $time) {
                $result[$time->format('H:i')] ??= ['time' => $time, 'professional' => $professional];
            }
        }

        ksort($result);

        return $result;
    }

    /** The time fits the professional's hours and does not clash with another appointment. */
    public function isFree(Professional $professional, CarbonImmutable $start, int $minutes, ?int $ignore = null): bool
    {
        return $this->fitsHours($professional, $start, $minutes)
            && $this->clashes($professional, $start, $start->addMinutes(max(1, $minutes)), $ignore)->isEmpty();
    }

    /** The time is inside one working piece of the professional (hours, break and time off; clashes not counted). */
    public function fitsHours(Professional $professional, CarbonImmutable $start, int $minutes): bool
    {
        $local = $start->setTimezone(AgendaSetting::timezone());
        $end = $local->addMinutes(max(1, $minutes));
        // The day of the start, and the day before (a shift that crosses midnight).
        $pieces = array_merge($this->hours->intervals($professional, $local->subDay()->startOfDay()), $this->hours->intervals($professional, $local->startOfDay()));

        return collect($pieces)->contains(fn (array $piece): bool => $local->gte($piece[0]) && $end->lte($piece[1]));
    }

    /**
     * Why an appointment is outside the professional's hours, for the desk's warning: "day_off" (no working time
     * that day), "outside" (before/after the hours, in the break or in a time off) or null when it fits.
     */
    public function outsideHours(Appointment $appointment): ?string
    {
        $professional = $appointment->professional;
        $start = CarbonImmutable::instance($appointment->starts_at);
        $minutes = max(1, (int) $start->diffInMinutes($appointment->ends_at));

        if ($professional === null || $this->fitsHours($professional, $start, $minutes)) {
            return null;
        }

        return $this->hours->intervals($professional, $start->setTimezone(AgendaSetting::timezone())->startOfDay()) === [] ? 'day_off' : 'outside';
    }

    /**
     * Other appointments of the professional in this time (for the desk's warning).
     *
     * @return Collection<int, Appointment>
     */
    public function clashes(Professional $professional, CarbonImmutable $start, CarbonImmutable $end, ?int $ignore = null): Collection
    {
        return Appointment::query()->holding()
            ->where('professional_id', $professional->getKey())
            ->where('starts_at', '<', $end->utc())
            ->where('ends_at', '>', $start->utc())
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore))
            ->get();
    }

    /**
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    protected function busy(Professional $professional, CarbonImmutable $from, CarbonImmutable $to, ?int $ignore): array
    {
        return $this->clashes($professional, $from, $to, $ignore)
            ->map(fn (Appointment $appointment): array => [$appointment->starts_at, $appointment->ends_at])
            ->all();
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     */
    protected function overlaps(array $busy, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($busy as [$from, $to]) {
            if ($start->lt($to) && $end->gt($from)) {
                return true;
            }
        }

        return false;
    }
}
