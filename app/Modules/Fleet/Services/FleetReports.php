<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Models\Expense;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Models\Maintenance;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\Wash;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Builder;

/**
 * Numbers of the Frota dashboard: for the last 1, 3, 6, 9 or 12 months, all vehicles or one.
 * Consumption is always distance ÷ volume from full tank to full tank (weighted, not an average of averages).
 */
class FleetReports
{
    public const PERIODS = [1, 3, 6, 9, 12];

    public const DEFAULT_PERIOD = 3;

    /**
     * @return array{spent_cents: int, volume: float, distance: int, consumption: ?float, cost_per_distance_cents: ?int, fuel_ups: int}
     */
    public function totals(int $months, ?int $vehicleId = null): array
    {
        [$from, $to] = $this->range($months);

        return $this->totalsBetween($from, $to, $vehicleId);
    }

    /**
     * The same numbers for any period (UTC limits), e.g. the spreadsheets.
     *
     * @return array{spent_cents: int, maintenance_cents: int, wash_cents: int, expense_cents: int, total_cents: int, volume: float, distance: int, consumption: ?float, cost_per_distance_cents: ?int, fuel_ups: int}
     */
    public function totalsBetween(CarbonImmutable $from, CarbonImmutable $to, ?int $vehicleId = null): array
    {
        $row = $this->fuelUps($from, $to, $vehicleId)
            ->selectRaw('COALESCE(SUM(total_cents), 0) AS spent, COALESCE(SUM(volume), 0) AS volume, COUNT(*) AS fuel_ups')
            ->first();
        $segments = $this->fuelUps($from, $to, $vehicleId)
            ->whereNotNull('consumption')
            ->selectRaw('COALESCE(SUM(distance), 0) AS distance, COALESCE(SUM(segment_volume), 0) AS volume')
            ->first();

        $distance = (int) $segments->distance;
        $spent = (int) $row->spent;
        $other = $this->otherCosts($from, $to, $vehicleId);

        return [
            'spent_cents' => $spent,
            'maintenance_cents' => $other['maintenance'],
            'wash_cents' => $other['wash'],
            'expense_cents' => $other['expense'],
            'total_cents' => $spent + array_sum($other),
            'volume' => round((float) $row->volume, 3),
            'distance' => $distance,
            'consumption' => (float) $segments->volume > 0 ? round($distance / (float) $segments->volume, 2) : null,
            // Everything the fleet cost (fuel of the measured stretches + maintenance, washes, other expenses) per distance.
            'cost_per_distance_cents' => $distance > 0 ? (int) round(($this->segmentSpent($from, $to, $vehicleId) + array_sum($other)) / $distance) : null,
            'fuel_ups' => (int) $row->fuel_ups,
        ];
    }

    /**
     * Month by month (oldest first): label, spent, volume, consumption.
     *
     * @return array<int, array{label: string, spent_cents: int, volume: float, consumption: ?float}>
     */
    public function monthly(int $months, ?int $vehicleId = null): array
    {
        $timezone = FilamentTimezone::get();
        $start = CarbonImmutable::now($timezone)->startOfMonth()->subMonths($months - 1);
        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $from = $start->addMonths($i);
            $to = $from->endOfMonth();
            $spent = (int) $this->fuelUps($from->utc(), $to->utc(), $vehicleId)->sum('total_cents');
            $volume = (float) $this->fuelUps($from->utc(), $to->utc(), $vehicleId)->sum('volume');
            $segments = $this->fuelUps($from->utc(), $to->utc(), $vehicleId)
                ->whereNotNull('consumption')
                ->selectRaw('COALESCE(SUM(distance), 0) AS distance, COALESCE(SUM(segment_volume), 0) AS volume')
                ->first();

            $other = $this->otherCosts($from->utc(), $to->utc(), $vehicleId);

            $series[] = [
                'label' => ucfirst($from->locale(app()->getLocale())->translatedFormat('M/y')),
                'spent_cents' => $spent,
                'maintenance_cents' => $other['maintenance'],
                'wash_cents' => $other['wash'],
                'expense_cents' => $other['expense'],
                'volume' => round($volume, 2),
                'consumption' => (float) $segments->volume > 0 ? round((int) $segments->distance / (float) $segments->volume, 2) : null,
            ];
        }

        return $series;
    }

    /**
     * Maintenance, washes and other expenses in the period (dates in the company time zone).
     *
     * @return array{maintenance: int, wash: int, expense: int}
     */
    public function otherCosts(CarbonImmutable $from, CarbonImmutable $to, ?int $vehicleId = null): array
    {
        $timezone = FilamentTimezone::get();
        $range = [$from->timezone($timezone)->toDateString(), $to->timezone($timezone)->toDateString()];
        $vehicle = fn (Builder $query) => $vehicleId ? $query->where('vehicle_id', $vehicleId) : $query;

        return [
            'maintenance' => (int) $vehicle(Maintenance::query()->whereBetween('performed_on', $range))->sum('total_cents'),
            'wash' => (int) $vehicle(Wash::query()->whereBetween('washed_on', $range))->sum('cost_cents'),
            'expense' => (int) $vehicle(Expense::query()->whereBetween('spent_on', $range))->sum('amount_cents'),
        ];
    }

    /**
     * Vehicles whose last full tank was much worse than their own average (possible mechanical problem).
     *
     * @return array<int, array{vehicle: Vehicle, last: float, average: float, drop_percent: int}>
     */
    public function consumptionAlerts(): array
    {
        $alerts = [];
        $fuel = app(FuelService::class);

        foreach (Vehicle::query()->countingInLimit()->get() as $vehicle) {
            $last = FuelUp::query()->completed()->where('vehicle_id', $vehicle->getKey())->whereNotNull('consumption')->latest('fueled_at')->first();

            if ($last === null) {
                continue;
            }

            $average = $fuel->averageConsumption($vehicle, now()->subMonths(6), $last->getKey());

            if ($average !== null && (float) $last->consumption < $average * FuelService::HIGH_CONSUMPTION_RATIO) {
                $alerts[] = [
                    'vehicle' => $vehicle,
                    'last' => (float) $last->consumption,
                    'average' => $average,
                    'drop_percent' => (int) round((1 - (float) $last->consumption / $average) * 100),
                ];
            }
        }

        return $alerts;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} start of the first month and now (UTC)
     */
    public function range(int $months): array
    {
        $months = in_array($months, self::PERIODS, true) ? $months : self::DEFAULT_PERIOD;
        $timezone = FilamentTimezone::get();

        return [
            CarbonImmutable::now($timezone)->startOfMonth()->subMonths($months - 1)->utc(),
            CarbonImmutable::now($timezone)->endOfMonth()->utc(),
        ];
    }

    /**
     * @return Builder<FuelUp>
     */
    protected function fuelUps(CarbonImmutable $from, CarbonImmutable $to, ?int $vehicleId): Builder
    {
        return FuelUp::query()
            ->completed()
            ->whereBetween('fueled_at', [$from, $to])
            ->when($vehicleId, fn (Builder $query) => $query->where('vehicle_id', $vehicleId));
    }

    /** Money of the fuel-ups that have a measured distance (so cost per km compares like with like). */
    protected function segmentSpent(CarbonImmutable $from, CarbonImmutable $to, ?int $vehicleId): int
    {
        // Cost of the volume used in the measured segments: segment volume × average unit price of the period.
        $row = $this->fuelUps($from, $to, $vehicleId)
            ->selectRaw('COALESCE(SUM(total_cents), 0) AS spent, COALESCE(SUM(volume), 0) AS volume')
            ->first();
        $segmentVolume = (float) $this->fuelUps($from, $to, $vehicleId)->whereNotNull('consumption')->sum('segment_volume');

        return (float) $row->volume > 0 ? (int) round((int) $row->spent / (float) $row->volume * $segmentVolume) : 0;
    }
}
