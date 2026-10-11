<?php

namespace App\Http\Responses;

use App\Tenancy\TenantContext;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Http\RedirectResponse;

/**
 * After signing out: a "you signed out" page of the company, with a button to sign in again.
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $tenant = app(TenantContext::class)->current();

        return redirect()->to($tenant ? $tenant->url('/auth/signed-out') : route('central.home'));
    }
}
