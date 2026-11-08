<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language: the choice made in this app, then the session, then the language of the Kiban account, then the company's,
 * then the browser, then the default. Someone not logged in (the company's customer on /agendar, a quote or a report link)
 * gets the browser's language before the company's: a customer whose phone is in Spanish reads the page in Spanish.
 */
class SetLocale
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('kiban.locales'));
        $tenant = $this->context->current();

        $browser = $this->browserLocale($request, $supported);

        $candidates = [
            $request->user()?->app_locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->user()?->locale,
            $request->user() === null ? $browser : null,
            $tenant?->license()->tenant('locale'),
            $browser,
        ];

        foreach ($candidates as $locale) {
            if (in_array($locale, $supported, true)) {
                App::setLocale($locale);
                break;
            }
        }

        return $next($request);
    }

    /**
     * The first language of the browser that we support ("es-AR" → "es", "pt" → "pt_BR"), or null when none is.
     * (Symfony's getPreferredLanguage() falls back to our first language, which would hide "none".)
     *
     * @param  list<string>  $supported
     */
    protected function browserLocale(Request $request, array $supported): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok($language, '_-') ?: '');

            foreach ($supported as $locale) {
                if (strcasecmp($locale, $language) === 0 || strtolower(strtok($locale, '_-') ?: '') === $primary) {
                    return $locale;
                }
            }
        }

        return null;
    }
}
