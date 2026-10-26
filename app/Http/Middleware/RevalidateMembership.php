<?php

namespace App\Http\Middleware;

use App\Auth\CoreSignIn;
use App\Licensing\CoreClient;
use App\Licensing\CoreUnavailableException;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every few minutes, asks the Core (with the app's own access) if the signed-in person still belongs to
 * the company: someone removed in the Core or in "People" loses access to it here too, and the role is updated.
 * One sign-in serves every company of the person (single address); each company checks its own membership.
 * It works the same for people who stay signed in for days on their phone.
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

        // Signed in, but this company is not (yet) theirs here: the Core sign-in of this company checks it
        // (it adds the company if the Core says the person belongs to it).
        if (! $user->belongsToTenant($tenant)) {
            return redirect()->guest($tenant->url('/auth/login'));
        }

        $checkedAt = (int) $session->get(CoreSignIn::checkedKey($tenant), 0);

        if ($checkedAt > now()->subMinutes((int) config('kiban.license.membership_check_minutes'))->getTimestamp()) {
            return $next($request);
        }

        try {
            $member = $this->core->member($tenant->subdomain, $user->core_id);
        } catch (CoreUnavailableException) {
            // Core unreachable: try again on a later page, keep the person working.
            return $next($request);
        }

        if ($member === null || ($member['status'] ?? null) === 'blocked') {
            $user->tenants()->detach($tenant->getKey());

            return $this->noAccess();
        }

        $this->signIn->syncMember($member, $tenant);
        $session->put(CoreSignIn::checkedKey($tenant), now()->getTimestamp());

        return $next($request);
    }

    /** Out of this company only: the person stays signed in for the other companies they belong to. */
    protected function noAccess(): Response
    {
        return response()->view('auth.no-access', ['tenant' => $this->context->get(), 'email' => null], 403);
    }
}
