<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Enums\VehicleEventType;
use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Fleet\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the odometer always up to date. Every fuel-up, maintenance, inspection and wash records a reading here.
 *
 * - A reading lower than the current odometer is refused (almost always a typing error).
 * - A manager can correct it (panel replaced, old mistake) with a reason; the correction stays in the history.
 * - The first record after the registration locks plate and chassis (see VehicleService).
 */
class OdometerService
{
    /** A jump bigger than this in one reading is refused as a probable typing error (unless a manager confirms). */
    public const MAX_JUMP = 20000;

    public function record(Vehicle $vehicle, int $value, OdometerSource $source, ?Model $record = null, ?User $user = null, ?CarbonInterface $at = null, bool $confirmedJump = false): OdometerReading
    {
        if (! $vehicle->isActiveForRecords()) {
            throw new FleetException(__('fleet.errors.vehicle_inactive'));
        }

        return DB::transaction(function () use ($vehicle, $value, $source, $record, $user, $at, $confirmedJump): OdometerReading {
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->getKey());

            if ($value < 0) {
                throw new FleetException(__('fleet.errors.odometer_negative'));
            }

            if ($value < $vehicle->odometer) {
                throw new FleetException(__('fleet.errors.odometer_backwards', ['current' => FleetFormat::distance($vehicle->odometer)]));
            }

            if (! $confirmedJump && $source !== OdometerSource::Registration && $value - $vehicle->odometer > self::MAX_JUMP) {
                throw new FleetException(__('fleet.errors.odometer_jump', ['jump' => FleetFormat::distance($value - $vehicle->odometer)]));
            }

            $reading = OdometerReading::create([
                'vehicle_id' => $vehicle->getKey(),
                'value' => $value,
                'source' => $source,
                'record_type' => $record?->getMorphClass(),
                'record_id' => $record?->getKey(),
                'read_at' => $at ?? now(),
                'user_id' => $user?->getKey(),
            ]);

            $vehicle->forceFill(['odometer' => $value, 'odometer_updated_at' => now()]);

            if ($source !== OdometerSource::Registration && $vehicle->identity_locked_at === null) {
                $vehicle->identity_locked_at = now();
            }

            $vehicle->save();

            return $reading;
        });
    }

    /**
     * Manager correction: sets the odometer to any value (also lower), with a mandatory reason.
     */
    public function correct(Vehicle $vehicle, int $value, string $reason, User $user): OdometerReading
    {
        if (trim($reason) === '') {
            throw new FleetException(__('fleet.errors.reason_required'));
        }

        return DB::transaction(function () use ($vehicle, $value, $reason, $user): OdometerReading {
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->getKey());
            $previous = $vehicle->odometer;

            $reading = OdometerReading::create([
                'vehicle_id' => $vehicle->getKey(),
                'value' => $value,
                'source' => OdometerSource::Correction,
                'read_at' => now(),
                'user_id' => $user->getKey(),
                'reason' => $reason,
            ]);

            $vehicle->forceFill(['odometer' => $value, 'odometer_updated_at' => now()])->save();

            $vehicle->events()->create([
                'type' => VehicleEventType::OdometerCorrected,
                'data' => ['from' => $previous, 'to' => $value],
                'reason' => $reason,
                'user_id' => $user->getKey(),
            ]);

            return $reading;
        });
    }
}
