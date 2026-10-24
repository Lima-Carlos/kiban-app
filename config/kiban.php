<?php

use App\Modules\Demo\DemoModule;
use App\Modules\Fleet\FleetModule;
use App\Modules\TimeClock\TimeClockModule;

return [

    /*
    |--------------------------------------------------------------------------
    | Kiban Core
    |--------------------------------------------------------------------------
    |
    | The app has no passwords and no billing of its own: people sign in through
    | the Core (single sign-on, OAuth2 + PKCE) and each company's signed license
    | says which modules it can use. "url" is what the browser uses; "internal_url"
    | is what this server uses (inside Docker they differ).
    |
    */

    'core' => [
        'url' => rtrim((string) env('KIBAN_CORE_URL', 'http://localhost:8080'), '/'),
        'internal_url' => rtrim((string) env('KIBAN_CORE_INTERNAL_URL', env('KIBAN_CORE_URL', 'http://localhost:8080')), '/'),
        'client_id' => env('KIBAN_CORE_CLIENT_ID'),
        'client_secret' => env('KIBAN_CORE_CLIENT_SECRET'),
        // Optional (recommended in production): the Core public key, fixed here instead of downloaded.
        'public_key' => env('KIBAN_CORE_PUBLIC_KEY'),
        // Seconds to wait for the Core before carrying on without it.
        'timeout' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Companies (tenants)
    |--------------------------------------------------------------------------
    |
    | Each company uses its own address: <subdomain>.<tenant_domain> (for example
    | padaria.kibancore.com.br, or padaria.localhost:8090 in development).
    |
    */

    'tenant_domain' => env('KIBAN_TENANT_DOMAIN', 'localhost'),

    'license' => [
        // Ask the Core for a new license this often (the Core may suggest another value).
        'refresh_seconds' => 300,
        // Warn the company when the last valid license expires in less than this (Core unreachable).
        'warn_hours' => 24,
        // Re-check that the signed-in person still belongs to the company this often.
        'membership_check_minutes' => 10,
    ],

    // Modules this app knows. The license decides which ones each company sees.
    'modules' => [
        DemoModule::class,
        FleetModule::class,
        TimeClockModule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Suggested access profiles
    |--------------------------------------------------------------------------
    |
    | Created for each company the first time (it can rename, change or delete them). Only the modules
    | this app has are kept; the HR profile appears when the time clock module exists.
    |
    */

    'access_profiles' => [
        'logistics' => ['name' => 'app.profiles.suggested.logistics', 'description' => 'app.profiles.suggested.logistics_help', 'roles' => ['frota' => 'manager']],
        'driver' => ['name' => 'app.profiles.suggested.driver', 'description' => 'app.profiles.suggested.driver_help', 'roles' => ['frota' => 'driver', 'ponto' => 'employee']],
        'mechanic' => ['name' => 'app.profiles.suggested.mechanic', 'description' => 'app.profiles.suggested.mechanic_help', 'roles' => ['frota' => 'mechanic', 'ponto' => 'employee']],
        'hr' => ['name' => 'app.profiles.suggested.hr', 'description' => 'app.profiles.suggested.hr_help', 'roles' => ['ponto' => 'manager']],
        'viewer' => ['name' => 'app.profiles.suggested.viewer', 'description' => 'app.profiles.suggested.viewer_help', 'roles' => ['frota' => 'viewer', 'ponto' => 'viewer']],
    ],

    'locales' => [
        'pt_BR' => 'Português (Brasil)',
        'en' => 'English',
        'es' => 'Español',
    ],

    // Fallback when the company has no time zone in its license.
    'timezone' => env('KIBAN_TIMEZONE', 'America/Sao_Paulo'),

    /*
    |--------------------------------------------------------------------------
    | Brand (same as the Core)
    |--------------------------------------------------------------------------
    */

    'brand' => [
        'blue' => '#044299',
        'gray' => '#39383A',

        'primary_palette' => [
            50 => '#EEF4FC',
            100 => '#D9E6F8',
            200 => '#B6CEF1',
            300 => '#87AEE6',
            400 => '#5286D6',
            500 => '#2A63C2',
            600 => '#044299',
            700 => '#03377F',
            800 => '#052D66',
            900 => '#072654',
            950 => '#051838',
        ],
    ],

];
