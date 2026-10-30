<?php

use App\Http\Controllers\Auth\SingleSignOnController;
use App\Http\Controllers\CentralController;
use App\Http\Controllers\Fleet\StationCheckController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\TimeClock\KioskController;
use App\Http\Controllers\TimeClock\PhoneQueueController;
use App\Http\Controllers\TimeClock\ReceiptController;
use App\Http\Middleware\EnsureLicense;
use App\Http\Middleware\RevalidateMembership;
use Illuminate\Support\Facades\Route;

/*
| The app's own pages (no company in the address): the companies of the person, the sign-in without a company
| and the return from the Core sign-in.
*/
Route::middleware(['central', 'locale'])->group(function (): void {
    Route::get('/entrar', [CentralController::class, 'home'])->name('central.home');
    Route::get('/entrar/ir', [CentralController::class, 'go'])->name('central.go');
    Route::get('/entrar/conta', [SingleSignOnController::class, 'centralLogin'])->name('central.login');
    Route::get('/auth/callback', [SingleSignOnController::class, 'callback'])->name('sso.callback');
});

/*
| A company's address (app.../<company>/...): sign-in through the Core. The screens themselves are the Filament panel.
*/
Route::middleware(['tenant', 'locale'])->group(function (): void {
    Route::get('/auth/login', [SingleSignOnController::class, 'login'])->name('login');
    Route::get('/auth/signed-out', [SingleSignOnController::class, 'signedOut'])->name('sso.signed-out');

    // Frota: page for the fuel station (no login). Few attempts per minute: codes cannot be guessed.
    Route::get('/posto', [StationCheckController::class, 'show'])->name('fleet.station');
    Route::post('/posto', [StationCheckController::class, 'check'])->middleware('throttle:10,1')->name('fleet.station.check');

    // Ponto: the company tablet as a time clock (no login; only a tablet activated by the manager).
    Route::get('/relogio', [KioskController::class, 'show'])->name('timeclock.kiosk');
    Route::post('/relogio/pin', [KioskController::class, 'pin'])->name('timeclock.kiosk.pin');
    Route::post('/relogio/bater', [KioskController::class, 'punch'])->name('timeclock.kiosk.punch');
    Route::post('/relogio/ciente', [KioskController::class, 'acknowledge'])->name('timeclock.kiosk.acknowledge');

    // Ponto: the phone's queue (punches kept without signal are sent here when it comes back) and the server's clock.
    Route::middleware(['auth', EnsureLicense::class, RevalidateMembership::class])->group(function (): void {
        Route::get('/ponto/hora', [PhoneQueueController::class, 'clock'])->name('timeclock.clock');
        Route::post('/ponto/enviar', [PhoneQueueController::class, 'send'])->middleware('throttle:60,1')->name('timeclock.queue');
    });

    // Ponto: receipt of a punch at a signed address (works without a login, e.g. from the QR code on the tablet).
    Route::get('/ponto/comprovante/{punch}', [ReceiptController::class, 'show'])->middleware('signed')->whereNumber('punch')->name('timeclock.receipt');
    Route::get('/ponto/comprovante/{punch}/pdf', [ReceiptController::class, 'pdf'])->middleware('signed')->whereNumber('punch')->name('timeclock.receipt.pdf');
});

// Language switch (works on the app's own pages and inside every company).
Route::get('/idioma/{locale}', LocaleController::class)->name('locale.switch');

// Installable app (icon on the phone home screen).
Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');
