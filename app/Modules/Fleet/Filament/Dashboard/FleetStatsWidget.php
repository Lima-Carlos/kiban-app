<?php

namespace App\Modules\Fleet\Filament\Dashboard;

use App\Modules\Fleet\Services\FleetFormat;
use App\Modules\Fleet\Services\FleetReports;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FleetStatsWidget extends StatsOverviewWidget
{
    use ReadsFleetFilters;

    protected int|string|array $columnSpan = 'full';

    /** On the start page it says what it is; on the fleet dashboard the filters already say it. */
    protected function getHeading(): ?string
    {
        return ($this->pageFilters['home'] ?? false) ? __('fleet.dashboard.summary') : null;
    }

    protected function getStats(): array
    {
        $totals = app(FleetReports::class)->totals($this->months(), $this->vehicleId());

        return [
            Stat::make(__('fleet.dashboard.fuel_spent'), FleetFormat::money($totals['spent_cents']))
                ->description(trans_choice('fleet.dashboard.fuel_ups', $totals['fuel_ups'], ['count' => $totals['fuel_ups']]))
                ->icon(Heroicon::OutlinedBanknotes),
            Stat::make(__('fleet.dashboard.other_spent'), FleetFormat::money($totals['maintenance_cents'] + $totals['wash_cents'] + $totals['expense_cents']))
                ->description(__('fleet.dashboard.other_spent_help', ['maintenance' => FleetFormat::money($totals['maintenance_cents']), 'wash' => FleetFormat::money($totals['wash_cents']), 'expense' => FleetFormat::money($totals['expense_cents'])]))
                ->icon(Heroicon::OutlinedWrenchScrewdriver),
            Stat::make(__('fleet.dashboard.total_spent'), FleetFormat::money($totals['total_cents']))
                ->icon(Heroicon::OutlinedCalculator),
            Stat::make(__('fleet.dashboard.volume'), FleetFormat::volume($totals['volume']))
                ->icon(Heroicon::OutlinedBeaker),
            Stat::make(__('fleet.dashboard.distance'), FleetFormat::distance($totals['driven']))
                ->description(__('fleet.dashboard.distance_help'))
                ->icon(Heroicon::OutlinedMapPin),
            Stat::make(__('fleet.dashboard.consumption'), FleetFormat::consumption($totals['consumption']))
                ->icon(Heroicon::OutlinedArrowTrendingUp),
            Stat::make(__('fleet.dashboard.cost_per_distance'), FleetFormat::money($totals['cost_per_distance_cents']))
                ->description(__('fleet.dashboard.cost_per_distance_help'))
                ->icon(Heroicon::OutlinedCalculator),
        ];
    }
}
