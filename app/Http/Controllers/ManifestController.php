<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Web app manifest: lets the phone "install" the system with the Kiban icon. One app for every company (single
 * address): it opens on the last company used on the device.
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $name = (string) config('app.name');

        return response()->json([
            'name' => $name,
            'short_name' => mb_strimwidth($name, 0, 12, '…'),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => config('kiban.brand.blue'),
            'lang' => str_replace('_', '-', app()->getLocale()),
            'icons' => [
                ['src' => asset('images/brand/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('images/brand/icon.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => asset('images/brand/icon.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
