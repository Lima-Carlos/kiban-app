<?php

namespace App\Http\Controllers\TimeClock;

use App\Http\Controllers\Controller;
use App\Modules\TimeClock\Filament\PunchPage;
use App\Modules\TimeClock\Services\PhotoNoticeService;
use App\Modules\TimeClock\Services\PunchPhotoStore;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ReceiptService;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\People\PersonRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Clock in" works without signal, like WhatsApp: the phone keeps each punch in a queue (with the selfie, the
 * location and the time, estimated with the server's clock) and sends it here as soon as the signal comes back.
 * Each punch has its own code, so sending it twice counts once. Answers in JSON:
 * - "ok": recorded (or already recorded), with the time, the number and the receipt;
 * - "refused": a rule said no (e.g. a double tap, too old); the phone drops it and shows the reason;
 * - 401/403/419: not signed in or no access; the phone keeps it and tries again after a new sign-in.
 */
class PhoneQueueController extends Controller
{
    /**
     * The server's clock, so the phone can tell the time of a punch taken without signal, and the current form
     * token (a page opened from the phone's memory may carry an old one).
     */
    public function clock(): JsonResponse
    {
        return response()->json(['now' => now()->getTimestampMs(), 'token' => csrf_token()]);
    }

    public function send(Request $request, PunchService $punches, PhotoNoticeService $notices, ReceiptService $receipts): JsonResponse
    {
        abort_unless(PunchPage::canAccess(), 403);

        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'taken_at' => ['required', 'integer', 'min:0'],
            'photo' => ['nullable', 'string', 'max:3000000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'without_photo' => ['nullable', 'boolean'],
        ]);

        $employee = app(TimeClockAccess::class)->employeeFor($request->user());

        // Without the acknowledgement of the notice no photo is stored; "without photo" only when the company allows it.
        $declined = ($data['without_photo'] ?? false) && $notices->allowsWithoutPhoto();
        $photo = $declined || ! $notices->acknowledged($employee) ? null : ($data['photo'] ?? null);

        try {
            $punch = $punches->punch($employee, [
                'client_id' => $data['id'],
                'taken_at' => CarbonImmutable::createFromTimestampMs((int) $data['taken_at']),
                'photo_declined' => $declined,
                'photo_path' => app(PunchPhotoStore::class)->fromDataUrl($photo),
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'accuracy' => $data['accuracy'] ?? null,
                'device' => (string) $request->userAgent(),
            ], $request->user());
        } catch (PersonRuleException $exception) {
            return response()->json(['status' => 'refused', 'message' => $exception->getMessage(), 'now' => now()->getTimestampMs()]);
        }

        return response()->json([
            'status' => 'ok',
            'time' => $punch->localTime()->format('H:i'),
            'nsr' => $punch->nsr,
            'offline' => $punch->hasFlag($punch::OFFLINE),
            'receipt' => $receipts->url($punch),
            'now' => now()->getTimestampMs(),
        ]);
    }
}
