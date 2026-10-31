<?php

namespace App\Modules\Field\Filament\Dashboard;

use App\Connections\Bridges\FieldFleet;
use App\Connections\Bridges\FieldTimeClock;
use App\Models\User;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\Field\Services\FieldReports;
use Filament\Widgets\Widget;

/**
 * One line per technician in the month: done orders, revenue, time at the customer, punctuality, returns and
 * the customers' stars (each name opens the technician's orders). With the other systems, for managers only:
 * the utilization (Ponto: hours at the customers ÷ hours worked) and the estimated travel cost (Frota).
 */
class FieldTechniciansWidget extends Widget
{
    use ReadsFieldFilters;

    protected string $view = 'field.dashboard.technicians';

    protected int|string|array $columnSpan = 'full';

    /** @var array{rows: array<int, array<string, mixed>>, utilization: ?array, travel: ?array}|null */
    protected ?array $data = null;

    /**
     * @return array{rows: array<int, array<string, mixed>>, utilization: ?array<int, array{worked: int, service: int, percent: ?int}>, travel: ?array<int, array{km: int, cost_cents: int, orders: int}>}
     */
    public function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $reports = app(FieldReports::class);
        $month = $this->month();
        $rows = $reports->month($month)['technicians'];
        $manager = app(FieldAccess::class)->isManager();
        $utilization = null;
        $travel = null;

        if ($manager && app(FieldTimeClock::class)->enabled()) {
            $utilization = app(FieldTimeClock::class)->utilization($month, $reports->doneIn($month));

            // Technicians who clocked in but did not finish any order this month also get their line (0%).
            $listed = collect($rows)->map(fn (array $row): int => $row['technician']->getKey())->all();
            $technicians = app(FieldAccess::class)->technicians()->keyBy(fn (User $user): int => $user->getKey());

            foreach (array_diff(array_keys($utilization), $listed) as $userId) {
                if ($technicians->has($userId)) {
                    $rows[] = ['technician' => $technicians->get($userId)] + $reports->totals(collect());
                }
            }
        }

        if ($manager && app(FieldFleet::class)->enabled()) {
            $travel = app(FieldFleet::class)->byTechnician($reports->doneIn($month));
        }

        return $this->data = ['rows' => $rows, 'utilization' => $utilization ?: null, 'travel' => $travel ?: null];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->data()['rows'];
    }
}
