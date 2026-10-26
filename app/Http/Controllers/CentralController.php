<?php

namespace App\Http\Controllers;

use App\Http\Middleware\IdentifyTenant;
use App\Licensing\CoreUnavailableException;
use App\Licensing\LicenseManager;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app's own page (app.kibancore.com.br/entrar):
 * - signed in: straight to the last company used on this device (or the only one), or the choice;
 * - not signed in: "Sign in with my Kiban account" (the companies come from the Core), or the company name.
 */
class CentralController extends Controller
{
    public function home(Request $request): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return response()->view('central.home', ['companies' => null, 'notFound' => $request->boolean('not_found')]);
        }

        $companies = $user->tenants()->orderBy('name')->get();
        $last = $companies->firstWhere('subdomain', (string) $request->cookie(IdentifyTenant::LAST_COMPANY));

        return match (true) {
            $last !== null && ! $request->boolean('escolher') => redirect()->to($last->url()),
            $companies->count() === 1 => redirect()->to($companies->first()->url()),
            $companies->isEmpty() => response()->view('auth.no-access', ['tenant' => null, 'email' => $user->email], 403),
            default => response()->view('central.home', ['companies' => $companies, 'notFound' => false]),
        };
    }

    public function go(Request $request, LicenseManager $licenses): Response
    {
        $company = mb_strtolower(trim((string) $request->query('company')));
        // Accept "padaria" or a pasted address ("app.kibancore.com.br/padaria/...").
        $company = preg_replace('#^(https?://)?[^/]+/#', '', $company, 1) ?? $company;
        $company = explode('/', $company)[0];

        try {
            $tenant = preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $company) === 1 ? $licenses->resolve($company) : null;
        } catch (CoreUnavailableException) {
            return response()->view('tenant.unavailable', [], 503);
        }

        if (! $tenant instanceof Tenant) {
            return redirect()->route('central.home', ['not_found' => 1, 'company' => $company]);
        }

        return redirect()->to($tenant->url());
    }
}
