<?php

namespace App\Http\Middleware;

use App\Licensing\CoreUnavailableException;
use App\Licensing\LicenseManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the company of the address (padaria.<domain>) and makes it the current company.
 * The central address sends people to the "choose your company" page; unknown addresses get a 404.
 */
class IdentifyTenant
{
    public function __construct(
        protected TenantResolver $resolver,
        protected LicenseManager $licenses,
        protected TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if ($this->resolver->isCentral($host)) {
            return redirect()->route('central.home');
        }

        $subdomain = $this->resolver->subdomain($host);
        try {
            $tenant = $subdomain !== null ? $this->licenses->resolve($subdomain) : null;
        } catch (CoreUnavailableException) {
            return response()->view('tenant.unavailable', [], 503);
        }

        if ($tenant === null) {
            return response()->view('tenant.not-found', ['central' => route('central.home')], 404);
        }

        $this->context->set($tenant);

        FilamentTimezone::set($tenant->license()->tenant('timezone') ?: config('kiban.timezone'));

        return $next($request);
    }
}
