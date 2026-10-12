<?php

namespace App\Modules\Fleet\Filament\Dashboard;

use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Services\FleetReports;
use Filament\Widgets\ChartWidget;

/**
 * Money spent on fuel month by month. Maintenance and other expenses join this chart in part 3.
 */
class FuelSpendChartWidget extends ChartWidget
{
    use ReadsFleetFilters;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return __('fleet.dashboard.spend_chart', ['currency' => FleetSetting::current()->currency]);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $series = app(FleetReports::class)->monthly($this->months(), $this->vehicleId());

        return [
            'datasets' => [[
                'label' => __('fleet.dashboard.fuel_spent'),
                'data' => array_map(fn (array $month): float => round($month['spent_cents'] / 100, 2), $series),
                'backgroundColor' => config('kiban.brand.blue'),
            ]],
            'labels' => array_column($series, 'label'),
        ];
    }
}
