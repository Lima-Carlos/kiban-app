<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language: the choice made in this app, then the session, then the language of the Kiban account, then the company's,
 * then the browser, then the default.
 */
class SetLocale
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('kiban.locales'));
        $tenant = $this->context->current();

        $candidates = [
            $request->user()?->app_locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->user()?->locale,
            $tenant?->license()->tenant('locale'),
            $request->getPreferredLanguage($supported),
        ];

        foreach ($candidates as $locale) {
            if (in_array($locale, $supported, true)) {
                App::setLocale($locale);
                break;
            }
        }

        return $next($request);
    }
}
