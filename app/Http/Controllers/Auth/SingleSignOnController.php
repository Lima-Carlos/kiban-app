<?php

namespace App\Http\Controllers\Auth;

use App\Auth\CoreSignIn;
use App\Http\Controllers\Controller;
use App\Licensing\CoreClient;
use App\Licensing\CoreUnavailableException;
use App\Licensing\InvalidGrantException;
use App\Licensing\LicenseManager;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in through the Kiban Core (OAuth2 authorization code + PKCE). There are no passwords in this app.
 *
 *  1. app.../padaria/auth/login  → sends the person to the Core, for that company;
 *     app.../entrar/conta        → the same without a company (the person's companies come from the Core).
 *  2. app.../auth/callback       → the Core sends them back (one fixed address); the code is exchanged for
 *                                  tokens, the person is checked and signed in, and goes to the company.
 *
 * One sign-in serves every company of the person (single address). The browser that started the sign-in must
 * be the one that finishes it (nonce kept in its session), so nobody can make someone else's browser sign in.
 */
class SingleSignOnController extends Controller
{
    protected const STATE_PREFIX = 'kiban:sso-state:';

    public function __construct(
        protected CoreClient $core,
        protected CoreSignIn $signIn,
        protected TenantContext $context,
        protected LicenseManager $licenses,
    ) {}

    /** Sign-in for the company of the address. */
    public function login(Request $request): RedirectResponse
    {
        $tenant = $this->context->get();
        $user = Auth::user();

        if ($user instanceof User && $user->belongsToTenant($tenant)) {
            return redirect()->to($tenant->url());
        }

        return $this->start($request, $tenant);
    }

    /** Sign-in from the central page: the companies come from the Core. */
    public function centralLogin(Request $request): RedirectResponse
    {
        return $this->start($request, null);
    }

    public function callback(Request $request): Response
    {
        $flow = Cache::pull(self::STATE_PREFIX.$request->string('state'));
        $nonce = $request->session()->pull('kiban.sso_nonce');
        $tenant = is_array($flow) && $flow['tenant'] !== null ? Tenant::query()->find($flow['tenant']) : null;

        if (! is_array($flow) || ($flow['tenant'] !== null && $tenant === null) || ! is_string($nonce) || ! hash_equals($nonce, (string) $flow['nonce'])) {
            return $this->failure('expired', $tenant);
        }

        // The person clicked "cancel" in the Core, or the Core refused.
        if ($request->filled('error') || ! $request->filled('code')) {
            return $tenant ? redirect()->to($tenant->url('/auth/signed-out?reason=denied')) : redirect()->route('central.home');
        }

        try {
            $tokens = $this->core->exchangeCode((string) $request->string('code'), $flow['verifier'], $this->callbackUrl());
            $me = $this->core->me($tokens['access_token']);
        } catch (InvalidGrantException) {
            return $this->failure('expired', $tenant);
        } catch (CoreUnavailableException) {
            return $this->failure('core', $tenant);
        }

        if ($me === null) {
            return $this->failure('expired', $tenant);
        }

        return $tenant ? $this->enterCompany($request, $me, $tenant) : $this->enterCentral($request, $me);
    }

    public function signedOut(Request $request): View
    {
        return view('auth.signed-out', [
            'tenant' => $this->context->get(),
            'denied' => $request->query('reason') === 'denied',
        ]);
    }

    protected function start(Request $request, ?Tenant $tenant): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(96);
        $nonce = Str::random(40);

        $request->session()->put('kiban.sso_nonce', $nonce);
        Cache::put(self::STATE_PREFIX.$state, ['tenant' => $tenant?->getKey(), 'verifier' => $verifier, 'nonce' => $nonce], now()->addMinutes(15));

        $query = http_build_query([
            'client_id' => config('kiban.core.client_id'),
            'redirect_uri' => $this->callbackUrl(),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
            'code_challenge' => strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_'),
            'code_challenge_method' => 'S256',
            'ui_locales' => app()->getLocale(),
        ]);

        return redirect()->away(config('kiban.core.url').'/oauth/authorize?'.$query);
    }

    /**
     * @param  array<string, mixed>  $me
     */
    protected function enterCompany(Request $request, array $me, Tenant $tenant): Response
    {
        $membership = $this->signIn->membership($me, $tenant);

        if ($membership === null) {
            return response()->view('auth.no-access', ['tenant' => $tenant, 'email' => $me['email'] ?? null], 403);
        }

        $user = $this->signIn->sync($me, $tenant, $membership);
        $this->signIn->login($user, $request->session(), $tenant);

        return redirect()->intended($tenant->url());
    }

    /**
     * Every company of the person (from the Core) is added; one company opens at once, several go to the choice.
     *
     * @param  array<string, mixed>  $me
     */
    protected function enterCentral(Request $request, array $me): Response
    {
        $user = null;
        $tenants = [];

        foreach ((array) ($me['customers'] ?? []) as $customer) {
            try {
                $tenant = filled($customer['subdomain'] ?? null) ? $this->licenses->resolve((string) $customer['subdomain']) : null;
            } catch (CoreUnavailableException) {
                return $this->failure('core');
            }

            if ($tenant !== null) {
                $user = $this->signIn->sync($me, $tenant, $customer);
                $tenants[] = $tenant;
            }
        }

        if ($user === null) {
            return response()->view('auth.no-access', ['tenant' => null, 'email' => $me['email'] ?? null], 403);
        }

        $this->signIn->login($user, $request->session(), ...$tenants);

        return count($tenants) === 1 ? redirect()->to($tenants[0]->url()) : redirect()->route('central.home');
    }

    protected function failure(string $reason, ?Tenant $tenant = null): Response
    {
        return response()->view('auth.failed', ['reason' => $reason, 'tenant' => $tenant], 400);
    }

    protected function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/callback';
    }
}
