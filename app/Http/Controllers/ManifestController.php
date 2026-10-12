<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web app manifest: lets the phone "install" the system with the Kiban icon, named after the company,
 * opening straight on its start page.
 */
class ManifestController extends Controller
{
    public function __invoke(Request $request, TenantResolver $resolver): JsonResponse
    {
        $subdomain = $resolver->subdomain($request->getHost());
        $tenant = $subdomain !== null ? Tenant::query()->where('subdomain', $subdomain)->first() : null;
        $name = $tenant ? $tenant->name : config('app.name');

        return response()->json([
            'name' => $tenant ? config('app.name').' · '.$name : $name,
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
