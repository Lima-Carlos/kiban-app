<?php

namespace App\Http\Controllers;

use App\Licensing\CoreUnavailableException;
use App\Licensing\LicenseManager;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central address: the person types the company address and is sent to it.
 */
class CentralController extends Controller
{
    public function home(Request $request): View
    {
        return view('central.home', ['notFound' => $request->boolean('not_found')]);
    }

    public function go(Request $request, LicenseManager $licenses): Response
    {
        $company = mb_strtolower(trim((string) $request->query('company')));
        // Accept "padaria" or a pasted "padaria.kibancore.com.br".
        $company = explode('.', preg_replace('#^https?://#', '', $company))[0];

        try {
            $tenant = preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $company) === 1 ? $licenses->resolve($company) : null;
        } catch (CoreUnavailableException) {
            return response()->view('tenant.unavailable', [], 503);
        }

        if ($tenant === null) {
            return redirect()->route('central.home', ['not_found' => 1, 'company' => $company]);
        }

        return redirect()->away($tenant->url());
    }
}
