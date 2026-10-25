<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Kiosk;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * The company tablets in "time clock mode" and the employees' PIN.
 *
 * - activating a tablet creates a secret kept only on it (cookie); here only its hash is stored. The person who
 *   activates is signed out of that tablet, so it never stays open with a manager's login;
 * - the PIN has 6 digits, made by the system (never chosen, so two people never share one) and shown once;
 *   it is kept as a keyed hash: it can be found, but not read back.
 */
class KioskService
{
    /** Name of the cookie that keeps the tablet's secret. */
    public const COOKIE = 'kiban_relogio';

    /** The tablet keeps the secret for 5 years (deactivating removes it from here). */
    public const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public function __construct(protected TenantContext $context) {}

    /** Turns the tablet into a time clock: the returned secret goes into the tablet's cookie. */
    public function activate(Kiosk $kiosk): string
    {
        $secret = Str::random(48);
        $kiosk->forceFill(['token_hash' => hash('sha256', $secret), 'activated_at' => now(), 'is_active' => true])->save();

        return $secret;
    }

    /** The tablet stops working as a time clock (e.g. lost or replaced). */
    public function deactivate(Kiosk $kiosk): void
    {
        $kiosk->forceFill(['token_hash' => null, 'is_active' => false])->save();
    }

    public function fromSecret(?string $secret): ?Kiosk
    {
        if (blank($secret)) {
            return null;
        }

        $kiosk = Kiosk::query()->with('workplace')->where('token_hash', hash('sha256', (string) $secret))->where('is_active', true)->first();
        $kiosk?->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $kiosk;
    }

    /** A new PIN for the employee (the old one stops working). Returned once, to be handed to the person. */
    public function newPin(Employee $employee): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Employee::query()->where('pin_lookup', $this->lookup($pin))->exists());

        $employee->forceFill(['pin_lookup' => $this->lookup($pin), 'pin_set_at' => now()])->save();

        return $pin;
    }

    public function employeeByPin(string $pin): ?Employee
    {
        $pin = preg_replace('/\D/', '', $pin);

        return strlen($pin) === 6
            ? Employee::query()->with('person')->active()->where('pin_lookup', $this->lookup($pin))->first()
            : null;
    }

    protected function lookup(string $pin): string
    {
        return hash_hmac('sha256', $this->context->get()->getKey().':'.$pin, (string) config('app.key'));
    }
}
