<?php

use App\Modules\Demo\DemoModule;
use App\Modules\Field\FieldModule;
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
        FieldModule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Kiban Ponto: official files (Brazil, Portaria 671)
    |--------------------------------------------------------------------------
    |
    | The developer of the program (Kiban) in the AFD file: CNPJ or CPF and the INPI registration of the
    | program (REP-P). Empty until the registration exists; the file is then a preview.
    |
    */

    'timeclock' => [
        'developer_document' => preg_replace('/\D/', '', (string) env('KIBAN_PONTO_DEVELOPER_DOCUMENT', '')),
        'inpi_registration' => (string) env('KIBAN_PONTO_INPI_REGISTRATION', ''),
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
        'technician' => ['name' => 'app.profiles.suggested.technician', 'description' => 'app.profiles.suggested.technician_help', 'roles' => ['campo' => 'technician', 'ponto' => 'employee']],
        'service_desk' => ['name' => 'app.profiles.suggested.service_desk', 'description' => 'app.profiles.suggested.service_desk_help', 'roles' => ['campo' => 'dispatcher']],
        'viewer' => ['name' => 'app.profiles.suggested.viewer', 'description' => 'app.profiles.suggested.viewer_help', 'roles' => ['frota' => 'viewer', 'ponto' => 'viewer', 'campo' => 'viewer']],
    ],

    'locales' => [
        'pt_BR' => 'Português (Brasil)',
        'en' => 'English',
        'es' => 'Español',
    ],

    // Fallback when the company has no time zone in its license.
    'timezone' => env('KIBAN_TIMEZONE', 'America/Sao_Paulo'),

    // Marco Civil access records (access_logs): days kept, never less than 6 months (183).
    'access_log_days' => (int) env('KIBAN_ACCESS_LOG_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Kiban Campo
    |--------------------------------------------------------------------------
    |
    | Addresses searched on the map (free OpenStreetMap service, at most one search per second, with a contact
    | e-mail as their rule asks). "off" turns the search off; a paid service can be added here later.
    |
    */

    'field' => [
        'locator' => env('KIBAN_FIELD_LOCATOR', 'openstreetmap'),
        'locator_contact' => env('KIBAN_FIELD_LOCATOR_CONTACT', 'privacidade@kibancore.com.br'),
        'locator_pause_ms' => (int) env('KIBAN_FIELD_LOCATOR_PAUSE_MS', 1100),
    ],

    /*
    | Alerts on the phone (web push, free, no external account): the server's key pair, made once with
    | "php artisan kiban:push-keys" and kept in the .env (never in Git). Without them, no alerts on the phone.
    */
    'push' => [
        'public_key' => env('KIBAN_PUSH_PUBLIC_KEY'),
        'private_key' => env('KIBAN_PUSH_PRIVATE_KEY'),
        'subject' => env('KIBAN_PUSH_SUBJECT', 'mailto:privacidade@kibancore.com.br'),
    ],

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
