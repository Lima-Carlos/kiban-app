<?php

use App\Http\Controllers\Auth\SingleSignOnController;
use App\Http\Controllers\CentralController;
use App\Http\Controllers\Fleet\StationCheckController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ManifestController;
use Illuminate\Support\Facades\Route;

/*
| Central address (APP_URL): choose the company and receive the person back from the Core sign-in.
*/
Route::middleware(['central', 'locale'])->group(function (): void {
    Route::get('/entrar', [CentralController::class, 'home'])->name('central.home');
    Route::get('/entrar/ir', [CentralController::class, 'go'])->name('central.go');
    Route::get('/auth/callback', [SingleSignOnController::class, 'callback'])->name('sso.callback');
});

/*
| Company address (<subdomain>.<domain>): sign-in through the Core. The screens themselves are the Filament panel.
*/
Route::middleware(['tenant', 'locale'])->group(function (): void {
    Route::get('/auth/login', [SingleSignOnController::class, 'login'])->name('login');
    Route::get('/auth/handoff', [SingleSignOnController::class, 'handoff'])->name('sso.handoff');
    Route::get('/auth/signed-out', [SingleSignOnController::class, 'signedOut'])->name('sso.signed-out');

    // Frota: page for the fuel station (no login). Few attempts per minute: codes cannot be guessed.
    Route::get('/posto', [StationCheckController::class, 'show'])->name('fleet.station');
    Route::post('/posto', [StationCheckController::class, 'check'])->middleware('throttle:10,1')->name('fleet.station.check');
});

// Language switch (works on the central address and on every company address).
Route::get('/idioma/{locale}', LocaleController::class)->name('locale.switch');

// Installable app (icon on the phone home screen).
Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');
