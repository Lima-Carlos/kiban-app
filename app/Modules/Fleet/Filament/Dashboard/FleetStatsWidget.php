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

    protected function getStats(): array
    {
        $totals = app(FleetReports::class)->totals($this->months(), $this->vehicleId());

        return [
            Stat::make(__('fleet.dashboard.fuel_spent'), FleetFormat::money($totals['spent_cents']))
                ->description(trans_choice('fleet.dashboard.fuel_ups', $totals['fuel_ups'], ['count' => $totals['fuel_ups']]))
                ->icon(Heroicon::OutlinedBanknotes),
            Stat::make(__('fleet.dashboard.volume'), FleetFormat::volume($totals['volume']))
                ->icon(Heroicon::OutlinedBeaker),
            Stat::make(__('fleet.dashboard.distance'), FleetFormat::distance($totals['distance']))
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
