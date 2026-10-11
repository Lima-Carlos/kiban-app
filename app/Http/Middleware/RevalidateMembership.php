<?php

namespace App\Http\Middleware;

use App\Auth\CoreSignIn;
use App\Licensing\CoreClient;
use App\Licensing\CoreUnavailableException;
use App\Licensing\InvalidGrantException;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every few minutes, asks the Core if the signed-in person still belongs to the company
 * (someone removed from the team in the Core loses access here too) and updates their role.
 *
 * If the Core is down, the person keeps working: only a clear "no" from the Core signs them out.
 */
class RevalidateMembership
{
    public function __construct(
        protected CoreClient $core,
        protected CoreSignIn $signIn,
        protected TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return $next($request);
        }

        $tenant = $this->context->get();
        $session = $request->session();

        if (! $user->belongsToTenant($tenant)) {
            return $this->signOut($request);
        }

        $checkedAt = (int) $session->get(CoreSignIn::SESSION_CHECKED, 0);

        if ($checkedAt > now()->subMinutes((int) config('kiban.license.membership_check_minutes'))->getTimestamp()) {
            return $next($request);
        }

        try {
            $me = $this->currentPerson($request);
        } catch (CoreUnavailableException) {
            // Core unreachable: try again on a later page, keep the person working.
            return $next($request);
        }

        $membership = $me !== null ? $this->signIn->membership($me, $tenant) : null;

        if ($me === null || $membership === null || (int) $me['id'] !== $user->core_id) {
            return $this->signOut($request);
        }

        $this->signIn->sync($me, $tenant, $membership);
        $session->put(CoreSignIn::SESSION_CHECKED, now()->getTimestamp());

        return $next($request);
    }

    /**
     * The person according to the Core, renewing the access token when needed. null = the Core said no.
     *
     * @return array<string, mixed>|null
     */
    protected function currentPerson(Request $request): ?array
    {
        $session = $request->session();
        $tokens = $this->signIn->tokens($session);

        if ($tokens === null) {
            return null;
        }

        if ($tokens['expires_at'] > now()->getTimestamp() && ($me = $this->core->me($tokens['access_token'])) !== null) {
            return $me;
        }

        if ($tokens['refresh_token'] === null) {
            return null;
        }

        try {
            $fresh = $this->core->refreshToken($tokens['refresh_token']);
        } catch (InvalidGrantException) {
            return null;
        }

        $this->signIn->storeTokens($fresh, $session);

        return $this->core->me($fresh['access_token']);
    }

    protected function signOut(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->view('auth.no-access', ['tenant' => $this->context->get(), 'email' => null], 403);
    }
}
