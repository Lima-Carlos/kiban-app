<?php

namespace App\Http\Controllers\TimeClock;

use App\Http\Controllers\Controller;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Kiosk;
use App\Modules\TimeClock\Services\KioskService;
use App\Modules\TimeClock\Services\PunchPhotoStore;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ReceiptService;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * The company tablet as a time clock (no login): the employee types the PIN, a photo is taken, the punch is
 * recorded and the receipt appears with a QR code. Only a tablet activated by the manager works here.
 * PIN attempts are limited per tablet.
 */
class KioskController extends Controller
{
    /** PIN attempts per minute on one tablet. */
    public const ATTEMPTS = 10;

    /** Minutes to take the photo after typing the PIN. */
    public const TICKET_MINUTES = 3;

    public function show(Request $request): View
    {
        $kiosk = $this->kiosk($request);

        return view('timeclock.kiosk', ['kiosk' => $kiosk, 'step' => $kiosk ? 'pin' : 'inactive']);
    }

    public function pin(Request $request, KioskService $kiosks): View
    {
        $kiosk = $this->kiosk($request) ?? abort(403);
        $key = 'kiosk-pin:'.$kiosk->getKey();

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            return view('timeclock.kiosk', ['kiosk' => $kiosk, 'step' => 'pin', 'error' => __('timeclock.kiosk.too_many', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $employee = $kiosks->employeeByPin((string) $request->input('pin'));

        if ($employee === null) {
            RateLimiter::hit($key, 60);

            return view('timeclock.kiosk', ['kiosk' => $kiosk, 'step' => 'pin', 'error' => __('timeclock.kiosk.wrong_pin')]);
        }

        RateLimiter::clear($key);

        return view('timeclock.kiosk', [
            'kiosk' => $kiosk,
            'step' => 'photo',
            'employee' => $employee,
            // Who typed the PIN, for this tablet, for a few minutes (nothing to change in the page).
            'ticket' => Crypt::encryptString(json_encode(['employee' => $employee->getKey(), 'kiosk' => $kiosk->getKey(), 'until' => now()->addMinutes(self::TICKET_MINUTES)->getTimestamp()])),
        ]);
    }

    public function punch(Request $request, PunchService $punches, ReceiptService $receipts): View
    {
        $kiosk = $this->kiosk($request) ?? abort(403);
        $employee = $this->ticketEmployee((string) $request->input('ticket'), $kiosk);

        if ($employee === null) {
            return view('timeclock.kiosk', ['kiosk' => $kiosk, 'step' => 'pin', 'error' => __('timeclock.kiosk.expired')]);
        }

        try {
            $punch = $punches->punch($employee, [
                'photo_path' => app(PunchPhotoStore::class)->fromDataUrl((string) $request->input('photo')),
                'device' => (string) $request->userAgent(),
            ], null, $kiosk);
        } catch (PersonRuleException $exception) {
            return view('timeclock.kiosk', ['kiosk' => $kiosk, 'step' => 'pin', 'error' => $exception->getMessage()]);
        }

        return view('timeclock.kiosk', [
            'kiosk' => $kiosk,
            'step' => 'done',
            'employee' => $employee,
            'punch' => $punch,
            'code' => $receipts->verificationCode($punch),
            'qr' => $receipts->qrCode($punch),
        ]);
    }

    protected function kiosk(Request $request): ?Kiosk
    {
        abort_unless(app(TenantContext::class)->get()->license()->canWrite(TimeClockModule::KEY), 404);

        return app(KioskService::class)->fromSecret($request->cookie(KioskService::COOKIE));
    }

    protected function ticketEmployee(string $ticket, Kiosk $kiosk): ?Employee
    {
        try {
            $data = json_decode(Crypt::decryptString($ticket), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (($data['kiosk'] ?? null) !== $kiosk->getKey() || ($data['until'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return Employee::query()->with(['person', 'workplace'])->active()->find($data['employee'] ?? null);
    }
}
