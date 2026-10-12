<?php

namespace App\Http\Controllers\Auth;

use App\Auth\CoreSignIn;
use App\Http\Controllers\Controller;
use App\Licensing\CoreClient;
use App\Licensing\CoreUnavailableException;
use App\Licensing\InvalidGrantException;
use App\Models\Tenant;
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
 *  1. padaria.<domain>/auth/login       → sends the person to the Core.
 *  2. <central>/auth/callback           → the Core sends them back here (one fixed address for every company);
 *                                          the code is exchanged for tokens and the person checked.
 *  3. padaria.<domain>/auth/handoff     → a one-time ticket signs the person in on the company's address.
 *
 * The browser that started the sign-in must be the one that finishes it (nonce kept in its session),
 * so nobody can make someone else's browser sign in with their account.
 */
class SingleSignOnController extends Controller
{
    protected const STATE_PREFIX = 'kiban:sso-state:';

    protected const TICKET_PREFIX = 'kiban:sso-ticket:';

    public function __construct(
        protected CoreClient $core,
        protected CoreSignIn $signIn,
        protected TenantContext $context,
    ) {}

    public function login(Request $request): RedirectResponse
    {
        $tenant = $this->context->get();

        if (Auth::check()) {
            return redirect()->to($tenant->url());
        }

        $state = Str::random(40);
        $verifier = Str::random(96);
        $nonce = Str::random(40);

        $request->session()->put('kiban.sso_nonce', $nonce);
        Cache::put(self::STATE_PREFIX.$state, ['tenant' => $tenant->getKey(), 'verifier' => $verifier, 'nonce' => $nonce], now()->addMinutes(15));

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

    public function callback(Request $request): Response
    {
        $flow = Cache::pull(self::STATE_PREFIX.$request->string('state'));

        if (! is_array($flow) || ($tenant = Tenant::query()->find($flow['tenant'])) === null) {
            return $this->failure('expired');
        }

        // The person clicked "cancel" in the Core, or the Core refused.
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->to($tenant->url('/auth/signed-out?reason=denied'));
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

        $ticket = Str::random(64);
        Cache::put(self::TICKET_PREFIX.$ticket, [
            'tenant' => $tenant->getKey(),
            'nonce' => $flow['nonce'],
            'me' => $me,
        ], now()->addMinute());

        return redirect()->to($tenant->url('/auth/handoff?ticket='.$ticket));
    }

    public function handoff(Request $request): Response
    {
        $tenant = $this->context->get();
        $data = Cache::pull(self::TICKET_PREFIX.$request->string('ticket'));
        $nonce = $request->session()->pull('kiban.sso_nonce');

        if (! is_array($data) || $data['tenant'] !== $tenant->getKey() || ! is_string($nonce) || ! hash_equals($nonce, $data['nonce'])) {
            return $this->failure('expired', $tenant);
        }

        $membership = $this->signIn->membership($data['me'], $tenant);

        if ($membership === null) {
            return response()->view('auth.no-access', ['tenant' => $tenant, 'email' => $data['me']['email'] ?? null], 403);
        }

        $user = $this->signIn->sync($data['me'], $tenant, $membership);
        $this->signIn->login($user, $request->session());

        return redirect()->intended($tenant->url());
    }

    public function signedOut(Request $request): View
    {
        return view('auth.signed-out', [
            'tenant' => $this->context->get(),
            'denied' => $request->query('reason') === 'denied',
        ]);
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
