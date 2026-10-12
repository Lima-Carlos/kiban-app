<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Enums\VehicleEventType;
use App\Modules\Fleet\Enums\VehicleStatus;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Vehicle;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Registering and changing vehicles, with the protections against reusing a plan slot:
 *
 * - plate and chassis lock after the first record; changing them needs a manager and a reason (logged);
 * - a vehicle with history is never deleted, only deactivated (deactivated ones do not count in the plan);
 * - reactivating needs a free slot in the plan; every deactivation and reactivation is logged.
 */
class VehicleService
{
    public function __construct(
        protected OdometerService $odometer,
        protected TenantContext $context,
    ) {}

    /** The plan limit of vehicles (with the tolerance) is already used. */
    public function limitReached(): bool
    {
        $max = $this->context->get()->license()->limitWithTolerance(FleetModule::KEY, FleetModule::LIMIT_VEHICLES);

        return $max !== null && Vehicle::query()->countingInLimit()->count() >= $max;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, int $odometer, ?User $user = null): Vehicle
    {
        if ($this->limitReached()) {
            throw new FleetException($this->limitMessage());
        }

        return DB::transaction(function () use ($data, $odometer, $user): Vehicle {
            $vehicle = new Vehicle($data);
            $vehicle->status = VehicleStatus::Active;
            $vehicle->save();

            $this->odometer->record($vehicle, $odometer, OdometerSource::Registration, user: $user);
            $vehicle->events()->create(['type' => VehicleEventType::Created, 'user_id' => $user?->getKey()]);

            return $vehicle->refresh();
        });
    }

    /**
     * Regular edit. Plate and chassis cannot change here once locked (use changeIdentity), and the
     * odometer and status have their own paths.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Vehicle $vehicle, array $data): Vehicle
    {
        unset($data['odometer'], $data['status'], $data['identity_locked_at'], $data['deactivated_at']);

        // Checked on a copy first: a refused change leaves the vehicle exactly as it was.
        $changed = (clone $vehicle)->fill($data);

        if ($vehicle->identity_locked_at !== null && $changed->isDirty(['plate', 'vin'])) {
            throw new FleetException(__('fleet.errors.identity_locked'));
        }

        $vehicle->fill($data);

        $vehicle->save();

        return $vehicle;
    }

    public function changeIdentity(Vehicle $vehicle, string $plate, ?string $vin, string $reason, User $user): Vehicle
    {
        if (trim($reason) === '') {
            throw new FleetException(__('fleet.errors.reason_required'));
        }

        return DB::transaction(function () use ($vehicle, $plate, $vin, $reason, $user): Vehicle {
            $from = ['plate' => $vehicle->plate, 'vin' => $vehicle->vin];
            $vehicle->forceFill(['plate' => $plate, 'vin' => $vin])->save();

            $vehicle->events()->create([
                'type' => VehicleEventType::IdentityChanged,
                'data' => ['from' => $from, 'to' => ['plate' => $vehicle->plate, 'vin' => $vehicle->vin]],
                'reason' => $reason,
                'user_id' => $user->getKey(),
            ]);

            return $vehicle;
        });
    }

    public function setStatus(Vehicle $vehicle, VehicleStatus $status, ?User $user = null, ?string $reason = null): Vehicle
    {
        if ($status === VehicleStatus::Inactive) {
            return $this->deactivate($vehicle, (string) $reason, $user);
        }

        if ($vehicle->status === VehicleStatus::Inactive) {
            return $this->reactivate($vehicle, $status, $user);
        }

        $vehicle->forceFill(['status' => $status])->save();

        return $vehicle;
    }

    public function deactivate(Vehicle $vehicle, string $reason, ?User $user = null): Vehicle
    {
        if (trim($reason) === '') {
            throw new FleetException(__('fleet.errors.reason_required'));
        }

        return DB::transaction(function () use ($vehicle, $reason, $user): Vehicle {
            $vehicle->forceFill(['status' => VehicleStatus::Inactive, 'deactivated_at' => now()])->save();
            $vehicle->events()->create(['type' => VehicleEventType::Deactivated, 'reason' => $reason, 'user_id' => $user?->getKey()]);

            return $vehicle;
        });
    }

    public function reactivate(Vehicle $vehicle, VehicleStatus $status = VehicleStatus::Active, ?User $user = null): Vehicle
    {
        if ($this->limitReached()) {
            throw new FleetException($this->limitMessage());
        }

        return DB::transaction(function () use ($vehicle, $status, $user): Vehicle {
            $vehicle->forceFill(['status' => $status, 'deactivated_at' => null])->save();
            $vehicle->events()->create(['type' => VehicleEventType::Reactivated, 'user_id' => $user?->getKey()]);

            return $vehicle;
        });
    }

    /** Only a vehicle without any record besides its registration can be deleted. */
    public function canDelete(Vehicle $vehicle): bool
    {
        return $vehicle->identity_locked_at === null
            && ! $vehicle->odometerReadings()->where('source', '!=', OdometerSource::Registration->value)->exists();
    }

    protected function limitMessage(): string
    {
        return __('app.denied.limit', ['limit' => $this->context->get()->license()->limit(FleetModule::KEY, FleetModule::LIMIT_VEHICLES)]);
    }
}
