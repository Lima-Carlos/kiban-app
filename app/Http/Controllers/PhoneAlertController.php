<?php

namespace App\Http\Controllers;

use App\Support\PhoneAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Keeps the phone of the signed-in person to receive alerts of this company (web push), when the person allows
 * it on the phone.
 */
class PhoneAlertController extends Controller
{
    public function __invoke(Request $request, PhoneAlerts $alerts): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $alerts->subscribe($request->user(), $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth']);

        return response()->json(['status' => 'ok']);
    }
}
