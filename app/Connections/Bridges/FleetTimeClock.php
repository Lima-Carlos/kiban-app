<?php

namespace App\Connections\Bridges;

use App\Models\User;
use App\Modules\Fleet\Enums\VehicleStatus;
use App\Modules\Fleet\Filament\Inspections\InspectionResource;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\InspectionService;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\TimeClockModule;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Kiban Frota + Kiban Ponto: a driver who just clocked in sees "Do the vehicle's inspection now" for each of
 * their vehicles still without the inspection of the period (when the company asks for inspections). Only a
 * suggestion with the link; nothing is blocked.
 */
class FleetTimeClock
{
    public function __construct(
        protected TenantContext $context,
        protected TimeClockAccess $clock,
        protected FleetAccess $fleet,
        protected InspectionService $inspections,
    ) {}

    public function enabled(): bool
    {
        $license = $this->context->get()->license();

        return $license->hasModule(TimeClockModule::KEY) && $license->canWrite(FleetModule::KEY);
    }

    /**
     * The person's vehicles waiting for an inspection, while they are at work (an odd number of punches today).
     *
     * @return Collection<int, array{vehicle: Vehicle, url: string}>
     */
    public function inspectionsAfterPunch(User $user, int $punchesToday): Collection
    {
        if (! $this->enabled() || $punchesToday % 2 === 0 || $this->clock->employeeFor($user) === null) {
            return collect();
        }

        $driver = $this->fleet->driverFor($user);

        // A "reserve" driver (any vehicle) has no vehicle of their own to suggest.
        if ($driver === null || $driver->any_vehicle || ! $this->inspections->canInspect($user)) {
            return collect();
        }

        return $driver->vehicles()->where('status', VehicleStatus::Active->value)->orderBy('plate')->get()
            ->filter(fn (Vehicle $vehicle): bool => $this->inspections->isDue($vehicle))
            ->map(fn (Vehicle $vehicle): array => ['vehicle' => $vehicle, 'url' => InspectionResource::getUrl('do', ['vehicle' => $vehicle->getKey()])])
            ->values();
    }
}
