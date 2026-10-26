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
 * Finds the company of the address (app.kibancore.com.br/padaria/...) and makes it the current company.
 * Without a company in the address, people go to the central page; unknown companies get a 404.
 * The last company visited is remembered (the central page and the installed app open it).
 */
class IdentifyTenant
{
    /** Cookie with the last company visited on this device. */
    public const LAST_COMPANY = 'kiban_empresa';

    public function __construct(
        protected TenantResolver $resolver,
        protected LicenseManager $licenses,
        protected TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $subdomain = $this->resolver->slug($request);

        if ($subdomain === null) {
            return redirect()->route('central.home');
        }

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

        $response = $next($request);

        if (! $request->hasCookie(self::LAST_COMPANY) || $request->cookie(self::LAST_COMPANY) !== $tenant->subdomain) {
            $response->headers->setCookie(cookie()->forever(self::LAST_COMPANY, $tenant->subdomain, path: '/', secure: $request->isSecure(), httpOnly: true, sameSite: 'lax'));
        }

        return $response;
    }
}
