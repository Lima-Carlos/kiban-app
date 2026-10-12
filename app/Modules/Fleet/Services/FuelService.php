<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Enums\FuelUpFlag;
use App\Modules\Fleet\Enums\FuelUpStatus;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fuel-ups with an authorization code:
 *
 * 1. authorize(): the person picks the vehicle and the station, types the odometer and takes a dashboard photo.
 *    The odometer is recorded and a single-use code is generated (valid for CODE_HOURS).
 * 2. The station checks the code (StationCheckController): plate, driver, fuel allowed, tank capacity.
 * 3. complete(): volume, amount, full tank? and the receipt photo. The consumption (e.g. km/l) is calculated
 *    from full tank to full tank, and warnings are raised (over capacity, wrong fuel, high consumption...).
 *
 * Managers can also type a fuel-up directly (register()), for example a receipt received later.
 */
class FuelService
{
    /** How long the code can be used at the station. */
    public const CODE_HOURS = 2;

    /** After this, an unfinished fuel-up expires. */
    public const FINISH_HOURS = 24;

    /** Letters and numbers that cannot be confused when read aloud (no 0/O, 1/I, 5/S, 8/B...). */
    protected const CODE_ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    /** Consumption this much worse than the vehicle's average raises a warning. */
    public const HIGH_CONSUMPTION_RATIO = 0.75;

    /** Small margin over the tank capacity (the hose, rounding). */
    protected const CAPACITY_MARGIN = 1.05;

    public function __construct(
        protected OdometerService $odometer,
        protected FleetAccess $access,
    ) {}

    /**
     * Vehicles the person may fuel: managers and mechanics any active vehicle; drivers their own
     * (or any, if "any vehicle" is on in their record).
     *
     * @return array<int, int>|null null = any active vehicle
     */
    public function ownVehicleIds(User $user): ?array
    {
        return match ($this->access->fleetRole($user)) {
            FleetRole::Manager, FleetRole::Mechanic => null,
            FleetRole::Driver => $this->access->visibleVehicleIds($user) ?? [],
            FleetRole::Viewer, FleetRole::None => [],
        };
    }

    public function canFuel(User $user): bool
    {
        return in_array($this->access->fleetRole($user), [FleetRole::Manager, FleetRole::Mechanic, FleetRole::Driver], true);
    }

    /** The vehicle is not one of the person's own (needs a reason, if the company allows it). */
    public function isOtherVehicle(User $user, Vehicle $vehicle): bool
    {
        $own = $this->ownVehicleIds($user);

        return $own !== null && ! in_array($vehicle->getKey(), $own, true);
    }

    public function authorize(Vehicle $vehicle, FuelStation $station, int $odometer, User $user, ?string $odometerPhoto = null, ?string $reason = null): FuelUp
    {
        if (! $this->canFuel($user)) {
            throw new FleetException(__('fleet.denied.fuel'));
        }

        if (! $vehicle->isActiveForRecords()) {
            throw new FleetException(__('fleet.errors.vehicle_inactive'));
        }

        if (! $station->is_active) {
            throw new FleetException(__('fleet.errors.station_inactive'));
        }

        $other = $this->isOtherVehicle($user, $vehicle);

        if ($other && ! FleetSetting::current()->allow_other_vehicle) {
            throw new FleetException(__('fleet.denied.not_your_vehicle'));
        }

        if ($other && blank($reason)) {
            throw new FleetException(__('fleet.errors.other_vehicle_reason'));
        }

        if ($vehicle->fuelUps()->where('status', FuelUpStatus::Authorized->value)->exists()) {
            throw new FleetException(__('fleet.errors.open_fuel_up'));
        }

        return DB::transaction(function () use ($vehicle, $station, $odometer, $user, $odometerPhoto, $reason, $other): FuelUp {
            $fuelUp = new FuelUp([
                'vehicle_id' => $vehicle->getKey(),
                'driver_id' => $this->access->driverFor($user)?->getKey(),
                'station_id' => $station->getKey(),
                'odometer' => $odometer,
                'odometer_photo_path' => $odometerPhoto,
                'reason' => $other ? $reason : null,
            ]);
            $fuelUp->forceFill([
                'status' => FuelUpStatus::Authorized,
                'code' => $this->newCode(),
                'code_expires_at' => now()->addHours(self::CODE_HOURS),
                'created_by' => $user->getKey(),
                'flags' => $other ? [FuelUpFlag::OtherVehicle->value] : [],
            ])->save();

            $this->odometer->record($vehicle, $odometer, OdometerSource::Fuel, $fuelUp, $user);

            return $fuelUp;
        });
    }

    /**
     * Station check: the open fuel-up of this code, or null (wrong, used or expired code).
     */
    public function findForStation(string $code): ?FuelUp
    {
        $fuelUp = FuelUp::query()
            ->with(['vehicle', 'driver', 'station', 'creator'])
            ->where('status', FuelUpStatus::Authorized->value)
            ->where('code', static::normalizeCode($code))
            ->first();

        if ($fuelUp === null || ! $fuelUp->codeIsValid()) {
            return null;
        }

        if ($fuelUp->station_checked_at === null) {
            $fuelUp->forceFill(['station_checked_at' => now()])->save();
        }

        return $fuelUp;
    }

    public function complete(FuelUp $fuelUp, float $volume, int $totalCents, bool $fullTank, User $user, ?string $receiptPath = null, ?FuelType $fuel = null): FuelUp
    {
        if (! $fuelUp->isOpen()) {
            throw new FleetException(__('fleet.errors.fuel_up_closed'));
        }

        $this->validateAmounts($volume, $totalCents);

        $flags = (array) $fuelUp->flags;

        if ($fuelUp->code_expires_at?->isPast()) {
            $flags[] = FuelUpFlag::Late->value;
        }

        $fuelUp->forceFill([
            'status' => FuelUpStatus::Completed,
            'volume' => $volume,
            'total_cents' => $totalCents,
            'unit_price' => round($totalCents / 100 / $volume, 3),
            'full_tank' => $fullTank,
            'fuel' => $fuel ?? $this->defaultFuel($fuelUp->vehicle),
            'receipt_path' => $receiptPath,
            'fueled_at' => now(),
            'completed_by' => $user->getKey(),
            'completed_at' => now(),
            'flags' => $flags,
        ]);

        return $this->finish($fuelUp);
    }

    /**
     * Manager types a fuel-up directly (no code): e.g. paid by card, receipt received later.
     *
     * @param  array{vehicle: Vehicle, station: ?FuelStation, odometer: int, volume: float, total_cents: int, full_tank: bool, fuel?: ?FuelType, fueled_at?: ?CarbonInterface, receipt_path?: ?string, notes?: ?string, driver_id?: ?int}  $data
     */
    public function register(array $data, User $user): FuelUp
    {
        if (! $this->access->isManager($user)) {
            throw new FleetException(__('fleet.denied.manager'));
        }

        $this->validateAmounts($data['volume'], $data['total_cents']);

        $vehicle = $data['vehicle'];
        $fueledAt = $data['fueled_at'] ?? now();

        return DB::transaction(function () use ($data, $user, $vehicle, $fueledAt): FuelUp {
            $fuelUp = new FuelUp([
                'vehicle_id' => $vehicle->getKey(),
                'driver_id' => $data['driver_id'] ?? null,
                'station_id' => $data['station']?->getKey(),
                'odometer' => $data['odometer'],
                'volume' => $data['volume'],
                'total_cents' => $data['total_cents'],
                'full_tank' => $data['full_tank'],
                'fuel' => $data['fuel'] ?? $this->defaultFuel($vehicle),
                'receipt_path' => $data['receipt_path'] ?? null,
                'fueled_at' => $fueledAt,
                'notes' => $data['notes'] ?? null,
            ]);
            $fuelUp->forceFill([
                'status' => FuelUpStatus::Completed,
                'unit_price' => round($data['total_cents'] / 100 / $data['volume'], 3),
                'created_by' => $user->getKey(),
                'completed_by' => $user->getKey(),
                'completed_at' => now(),
                'flags' => [],
            ])->save();

            $this->odometer->record($vehicle, $data['odometer'], OdometerSource::Fuel, $fuelUp, $user, $fueledAt->lt(now()->subMinute()) ? $fueledAt : null);

            return $this->finish($fuelUp);
        });
    }

    public function cancel(FuelUp $fuelUp, string $reason, User $user): FuelUp
    {
        if (! $fuelUp->isOpen()) {
            throw new FleetException(__('fleet.errors.fuel_up_closed'));
        }

        if (trim($reason) === '') {
            throw new FleetException(__('fleet.errors.reason_required'));
        }

        // The odometer reading stays: it was really read at the pump.
        $fuelUp->forceFill(['status' => FuelUpStatus::Canceled, 'cancel_reason' => $reason, 'completed_by' => $user->getKey(), 'completed_at' => now()])->save();

        return $fuelUp;
    }

    /**
     * Open fuel-ups not finished in time become "expired" (scheduler, hourly).
     */
    public function expireStale(): int
    {
        return FuelUp::query()
            ->where('status', FuelUpStatus::Authorized->value)
            ->where('created_at', '<', now()->subHours(self::FINISH_HOURS))
            ->update(['status' => FuelUpStatus::Expired->value, 'updated_at' => now()]);
    }

    /**
     * Average consumption of the vehicle (distance per volume) in the period, from full tank to full tank.
     */
    public function averageConsumption(Vehicle $vehicle, ?CarbonInterface $since = null, ?int $exceptId = null): ?float
    {
        $totals = FuelUp::query()
            ->completed()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereNotNull('consumption')
            ->when($since, fn ($query) => $query->where('fueled_at', '>=', $since))
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->selectRaw('SUM(distance) AS distance, SUM(segment_volume) AS volume')
            ->first();

        return $totals && (float) $totals->volume > 0 ? round((float) $totals->distance / (float) $totals->volume, 3) : null;
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** "K7MQ42" → "K7M-Q42" (easier to read aloud). */
    public static function formatCode(?string $code): string
    {
        return $code === null ? '—' : substr($code, 0, 3).'-'.substr($code, 3);
    }

    /**
     * Saves a completed fuel-up: consumption from the previous full tank and the warnings.
     */
    protected function finish(FuelUp $fuelUp): FuelUp
    {
        $vehicle = $fuelUp->vehicle()->firstOrFail();
        $flags = (array) $fuelUp->flags;

        if ($vehicle->tank_capacity !== null && (float) $fuelUp->volume > (float) $vehicle->tank_capacity * self::CAPACITY_MARGIN) {
            $flags[] = FuelUpFlag::OverCapacity->value;
        }

        if ($fuelUp->fuel !== null && ! in_array($fuelUp->fuel, $vehicle->fuel_type->acceptedFuels(), true)) {
            $flags[] = FuelUpFlag::WrongFuel->value;
        }

        if ($fuelUp->full_tank) {
            $this->calculateConsumption($fuelUp);

            $average = $this->averageConsumption($vehicle, now()->subMonths(6), $fuelUp->getKey());

            if ($fuelUp->consumption !== null && $average !== null && (float) $fuelUp->consumption < $average * self::HIGH_CONSUMPTION_RATIO) {
                $flags[] = FuelUpFlag::HighConsumption->value;
            }
        }

        $fuelUp->flags = array_values(array_unique($flags));
        $fuelUp->save();

        return $fuelUp;
    }

    /**
     * Full tank to full tank: distance since the previous full tank, divided by everything put in since then
     * (partial fuel-ups in between included).
     */
    protected function calculateConsumption(FuelUp $fuelUp): void
    {
        $previous = FuelUp::query()
            ->completed()
            ->where('vehicle_id', $fuelUp->vehicle_id)
            ->where('full_tank', true)
            ->where('odometer', '<', $fuelUp->odometer)
            ->whereKeyNot($fuelUp->getKey())
            ->orderByDesc('odometer')
            ->first();

        if ($previous === null) {
            return;
        }

        $partials = (float) FuelUp::query()
            ->completed()
            ->where('vehicle_id', $fuelUp->vehicle_id)
            ->where('full_tank', false)
            ->where('odometer', '>', $previous->odometer)
            ->where('odometer', '<', $fuelUp->odometer)
            ->sum('volume');

        $volume = $partials + (float) $fuelUp->volume;
        $distance = $fuelUp->odometer - $previous->odometer;

        if ($volume <= 0 || $distance <= 0) {
            return;
        }

        $fuelUp->forceFill([
            'distance' => $distance,
            'segment_volume' => round($volume, 3),
            'consumption' => round($distance / $volume, 3),
        ]);
    }

    protected function validateAmounts(float $volume, int $totalCents): void
    {
        if ($volume <= 0 || $volume > 5000) {
            throw new FleetException(__('fleet.errors.volume_invalid'));
        }

        if ($totalCents <= 0) {
            throw new FleetException(__('fleet.errors.amount_invalid'));
        }
    }

    protected function defaultFuel(Vehicle $vehicle): ?FuelType
    {
        $accepted = $vehicle->fuel_type->acceptedFuels();

        return count($accepted) === 1 ? $accepted[0] : null;
    }

    protected function newCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';

            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }

            if (! FuelUp::query()->where('status', FuelUpStatus::Authorized->value)->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new FleetException(__('fleet.errors.code_unavailable'));
    }
}
