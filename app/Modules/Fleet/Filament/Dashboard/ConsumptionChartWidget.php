<?php

namespace App\Modules\Fleet\Filament\Dashboard;

use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Services\FleetReports;
use Filament\Widgets\ChartWidget;

/**
 * Average consumption month by month (distance per volume).
 */
class ConsumptionChartWidget extends ChartWidget
{
    use ReadsFleetFilters;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        $settings = FleetSetting::current();

        return __('fleet.dashboard.consumption_chart', ['unit' => $settings->distance_unit.'/'.$settings->volume_unit]);
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = app(FleetReports::class)->monthly($this->months(), $this->vehicleId());

        return [
            'datasets' => [[
                'label' => __('fleet.dashboard.consumption'),
                'data' => array_column($series, 'consumption'),
                'borderColor' => config('kiban.brand.blue'),
                'backgroundColor' => 'rgba(4, 66, 153, 0.15)',
                'fill' => true,
                'spanGaps' => true,
                'tension' => 0.3,
            ]],
            'labels' => array_column($series, 'label'),
        ];
    }
}
