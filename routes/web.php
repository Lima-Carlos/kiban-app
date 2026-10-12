<?php

use App\Http\Controllers\Auth\SingleSignOnController;
use App\Http\Controllers\CentralController;
use App\Http\Controllers\LocaleController;
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
});

// Language switch (works on the central address and on every company address).
Route::get('/idioma/{locale}', LocaleController::class)->name('locale.switch');
