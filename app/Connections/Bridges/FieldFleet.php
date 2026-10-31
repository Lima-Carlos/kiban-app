<?php

namespace App\Connections\Bridges;

use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Fleet\Services\FleetReports;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Kiban Campo + Kiban Frota: the estimated cost of going to a customer.
 *
 * km driven to the order = the km the technician typed at arrival − the vehicle's previous reading of the same
 * day (an earlier order, the inspection, a fuel-up...). Cost = km × the vehicle's cost per km in the Frota (all it
 * cost in the last months ÷ what it drove, FleetReports). Without the km, the earlier reading of the day or the
 * cost per km, there is no estimate (nothing is shown). It is an estimate for managers, never a charge.
 */
class FieldFleet
{
    /** Months of the vehicle's costs used for its cost per km (the Frota dashboard's default). */
    public const COST_MONTHS = FleetReports::DEFAULT_PERIOD;

    /** More than this between two readings of the same day is not a trip to one customer (typing error). */
    public const MAX_KM = 500;

    /** @var array<int, ?int> cost per km of each vehicle, read once per request */
    protected array $costs = [];

    public function __construct(protected TenantContext $context, protected FleetReports $reports) {}

    public function enabled(): bool
    {
        $license = $this->context->get()->license();

        return $license->hasModule(FieldModule::KEY) && $license->hasModule(FleetModule::KEY);
    }

    /**
     * @return array{km: int, cost_cents: int, per_km_cents: int}|null
     */
    public function estimate(WorkOrder $order): ?array
    {
        if (! $this->enabled() || $order->vehicle_id === null || $order->check_in_km === null || $order->checked_in_at === null) {
            return null;
        }

        $dayStart = $order->checked_in_at->setTimezone(OrderResource::timezone())->startOfDay()->utc();

        $previous = OdometerReading::query()
            ->where('vehicle_id', $order->vehicle_id)
            ->whereBetween('read_at', [$dayStart, $order->checked_in_at])
            ->where('value', '<=', $order->check_in_km)
            ->where(fn ($query) => $query->whereNull('record_type')
                ->orWhere('record_type', '!=', $order->getMorphClass())
                ->orWhere('record_id', '!=', $order->getKey()))
            ->max('value');

        if ($previous === null) {
            return null;
        }

        $km = (int) $order->check_in_km - (int) $previous;
        $perKm = $this->costPerKm((int) $order->vehicle_id);

        if ($km <= 0 || $km > self::MAX_KM || $perKm === null || $perKm <= 0) {
            return null;
        }

        return ['km' => $km, 'cost_cents' => $km * $perKm, 'per_km_cents' => $perKm];
    }

    /**
     * The month per technician: km and estimated cost of the orders that have an estimate.
     *
     * @param  Collection<int, WorkOrder>  $orders
     * @return array<int, array{km: int, cost_cents: int, orders: int}> by technician (user id)
     */
    public function byTechnician(Collection $orders): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $result = [];

        foreach ($orders as $order) {
            $estimate = $this->estimate($order);

            if ($estimate === null || $order->technician_id === null) {
                continue;
            }

            $row = $result[$order->technician_id] ?? ['km' => 0, 'cost_cents' => 0, 'orders' => 0];
            $result[$order->technician_id] = ['km' => $row['km'] + $estimate['km'], 'cost_cents' => $row['cost_cents'] + $estimate['cost_cents'], 'orders' => $row['orders'] + 1];
        }

        return $result;
    }

    public function costPerKm(int $vehicleId): ?int
    {
        if (! array_key_exists($vehicleId, $this->costs)) {
            $this->costs[$vehicleId] = $this->reports->totals(self::COST_MONTHS, $vehicleId)['cost_per_distance_cents'];
        }

        return $this->costs[$vehicleId];
    }
}
