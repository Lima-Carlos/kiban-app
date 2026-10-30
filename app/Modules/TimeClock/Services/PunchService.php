<?php

namespace App\Modules\TimeClock\Services;

use App\Models\User;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Kiosk;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\People\PersonRuleException;
use App\Support\Geo;
use App\Support\ClientPort;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records punches, on the phone (selfie + location) or on the company tablet (PIN + photo).
 *
 * - the time is the server's; only a punch kept on the phone without signal and sent later keeps the time it
 *   was taken (estimated with the server's clock, flagged "without internet" for the manager);
 * - each punch gets the next number of the company (NSR) and the hash of the previous punch (a chain);
 * - nothing blocks the punch: outside the workplace radius, without location or without a photo, it is
 *   only flagged for the manager;
 * - a second tap within a minute is not a new punch (double taps);
 * - a punch sent twice from the phone's queue (same client_id) counts once.
 */
class PunchService
{
    /** Seconds in which a new punch of the same person is taken as a double tap. */
    public const DOUBLE_TAP_SECONDS = 60;

    /** A punch that arrives later than this after it was taken was kept on the phone without signal. */
    public const OFFLINE_AFTER_SECONDS = 120;

    /** Older punches from the phone's queue are refused (the manager adds them by a correction). */
    public const OFFLINE_MAX_DAYS = 7;

    public function __construct(protected TenantContext $context) {}

    /**
     * @param  array{latitude?: ?float, longitude?: ?float, accuracy?: ?int, photo_path?: ?string, photo_declined?: bool, device?: ?string, client_id?: ?string, taken_at?: ?CarbonInterface}  $data
     */
    public function punch(Employee $employee, array $data, ?User $user = null, ?Kiosk $kiosk = null): Punch
    {
        $clientId = isset($data['client_id']) && Str::isUuid($data['client_id']) ? strtolower($data['client_id']) : null;

        // Already received (the phone did not get the answer and sent it again).
        if ($clientId !== null && ($sent = Punch::query()->where('employee_id', $employee->getKey())->where('client_id', $clientId)->first())) {
            return $sent;
        }

        if (! $employee->is_active) {
            throw new PersonRuleException(__('timeclock.errors.employee_inactive'));
        }

        $flags = [];
        $punchedAt = CarbonImmutable::now()->utc()->startOfSecond();
        $takenAt = $kiosk === null && ($data['taken_at'] ?? null) instanceof CarbonInterface ? CarbonImmutable::instance($data['taken_at'])->utc()->startOfSecond() : null;

        if ($takenAt !== null && $takenAt->lt($punchedAt->subSeconds(self::OFFLINE_AFTER_SECONDS))) {
            if ($takenAt->lt($punchedAt->subDays(self::OFFLINE_MAX_DAYS))) {
                throw new PersonRuleException(__('timeclock.errors.offline_too_old', ['days' => self::OFFLINE_MAX_DAYS]));
            }

            if (app(MonthClosing::class)->isClosed($employee, $takenAt->setTimezone($employee->workplace?->timezone ?? FilamentTimezone::get()))) {
                throw new PersonRuleException(__('timeclock.errors.offline_month_closed'));
            }

            $punchedAt = $takenAt;
            $flags[] = Punch::OFFLINE;
        }

        $near = Punch::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('punched_at', [$punchedAt->subSeconds(self::DOUBLE_TAP_SECONDS - 1), $punchedAt->addSeconds(self::DOUBLE_TAP_SECONDS - 1)])
            ->latest('punched_at')
            ->first();

        if ($near !== null) {
            throw new PersonRuleException(__('timeclock.errors.double_tap', ['time' => $near->localTime()->format('H:i')]));
        }

        $workplace = $employee->workplace;

        if ($kiosk !== null) {
            // The tablet stays at the workplace: its place is the location.
            $workplace = $kiosk->workplace;
            $latitude = $workplace->latitude;
            $longitude = $workplace->longitude;
            $accuracy = null;
        } else {
            $latitude = isset($data['latitude']) && is_numeric($data['latitude']) ? (float) $data['latitude'] : null;
            $longitude = isset($data['longitude']) && is_numeric($data['longitude']) ? (float) $data['longitude'] : null;
            $accuracy = isset($data['accuracy']) && is_numeric($data['accuracy']) ? (int) round((float) $data['accuracy']) : null;

            if ($latitude === null || $longitude === null) {
                $flags[] = Punch::NO_LOCATION;
            }
        }

        $distance = $latitude !== null && $longitude !== null && $workplace?->hasPoint() && ! $employee->is_external
            ? Geo::distance($latitude, $longitude, $workplace->latitude, $workplace->longitude)
            : null;

        if ($kiosk === null && $distance !== null && $workplace->radius_meters && $distance > $workplace->radius_meters) {
            $flags[] = Punch::OUTSIDE_RADIUS;
        }

        if (blank($data['photo_path'] ?? null)) {
            $flags[] = ($data['photo_declined'] ?? false) ? Punch::PHOTO_DECLINED : Punch::NO_PHOTO;
        }

        return DB::transaction(function () use ($employee, $data, $user, $kiosk, $workplace, $latitude, $longitude, $accuracy, $distance, $flags, $punchedAt, $clientId): Punch {
            // One number after the other, even with two punches at the same second.
            $settings = TimeClockSetting::query()->lockForUpdate()->first() ?? TimeClockSetting::current();
            $nsr = (int) $settings->last_nsr + 1;
            $hash = static::chainHash((string) $settings->last_punch_hash, $nsr, $punchedAt->toIso8601ZuluString(), (int) $employee->getKey());

            $punch = new Punch;
            $punch->forceFill([
                'employee_id' => $employee->getKey(),
                'nsr' => $nsr,
                'punched_at' => $punchedAt,
                'timezone' => $workplace?->timezone ?? FilamentTimezone::get(),
                'source' => $kiosk ? Punch::KIOSK : Punch::PHONE,
                'kiosk_id' => $kiosk?->getKey(),
                'user_id' => $user?->getKey(),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy_meters' => $accuracy,
                'distance_meters' => $distance,
                'flags' => array_values(array_unique($flags)),
                'photo_path' => $data['photo_path'] ?? null,
                'device' => isset($data['device']) ? mb_substr((string) $data['device'], 0, 255) : null,
                // Marco Civil: where the punch came from (the tablet has no login, so this is its access record).
                'ip' => $this->fromVisit() ? request()->ip() : null,
                'port' => $this->fromVisit() ? ClientPort::of() : null,
                'hash' => $hash,
                'client_id' => $clientId,
            ])->save();

            $settings->forceFill(['last_nsr' => $nsr, 'last_punch_hash' => $hash])->save();

            return $punch;
        });
    }

    /** A real visit (phone or tablet), not a command such as the demo data: only then the IP and port mean something. */
    protected function fromVisit(): bool
    {
        return ! app()->runningInConsole() || app()->runningUnitTests();
    }

    /** The punch's link in the chain: the previous hash, its number, its time and the employee. */
    public static function chainHash(string $previous, int $nsr, string $time, int $employeeId): string
    {
        return hash('sha256', implode('|', [$previous, $nsr, $time, $employeeId]));
    }

    /**
     * Checks the chain of the company's punches: the first changed or missing one, or null when all is right.
     */
    public function brokenLink(): ?int
    {
        $previous = '';
        $expectedNsr = 1;

        foreach (Punch::query()->orderBy('nsr')->cursor() as $punch) {
            $hash = static::chainHash($previous, $punch->nsr, $punch->punched_at->toIso8601ZuluString(), (int) $punch->employee_id);

            if ($punch->nsr !== $expectedNsr || $punch->hash !== $hash) {
                return $punch->nsr;
            }

            $previous = $punch->hash;
            $expectedNsr++;
        }

        return null;
    }
}
