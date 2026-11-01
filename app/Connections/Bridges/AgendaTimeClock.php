<?php

namespace App\Connections\Bridges;

use App\Modules\Agenda\Models\Professional;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Services\DayCalculator;
use App\Modules\TimeClock\TimeClockModule;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kiban Core Agenda + Kiban Core Ponto: on the desk's calendar, who of the professionals has clocked in today
 * ("working", "on a break / left" or "not yet"). Only shows: nothing here records a punch (the punch stays an act of
 * the employee). The hours of the professionals also follow their Ponto schedule (WorkingHours).
 */
class AgendaTimeClock
{
    public function __construct(protected TenantContext $context) {}

    public function enabled(): bool
    {
        return $this->context->get()->license()->hasModule(TimeClockModule::KEY);
    }

    /**
     * Today's situation of each professional who clocks in: "in" (odd punches), "out" (even, at least one) or "none".
     *
     * @param  Collection<int, Professional>  $professionals
     * @return array<int, array{state: string, since: ?CarbonImmutable}> by professional id
     */
    public function today(Collection $professionals): array
    {
        if (! $this->enabled() || $professionals->isEmpty()) {
            return [];
        }

        $employees = Employee::query()->active()->with('workplace')->whereIn('person_id', $professionals->pluck('person_id'))->get()->keyBy('person_id');
        $result = [];

        foreach ($professionals as $professional) {
            $employee = $employees->get($professional->person_id);

            if ($employee === null) {
                continue;
            }

            $today = CarbonImmutable::now(app(DayCalculator::class)->timezone($employee));
            $punches = Punch::query()->where('employee_id', $employee->getKey())
                ->whereBetween('punched_at', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])
                ->orderBy('punched_at')
                ->pluck('punched_at');

            $result[$professional->getKey()] = [
                'state' => $punches->isEmpty() ? 'none' : ($punches->count() % 2 === 1 ? 'in' : 'out'),
                'since' => $punches->isEmpty() ? null : CarbonImmutable::parse($punches->last()),
            ];
        }

        return $result;
    }
}
