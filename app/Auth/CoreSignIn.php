<?php

namespace App\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Session\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Turns the person returned by the Core (/api/v1/me at sign-in, or the company's people list later) into a
 * signed-in user of a company. The person stays signed in for 30 days ("remember me"); their access is
 * re-checked with the Core every few minutes (RevalidateMembership).
 */
class CoreSignIn
{
    public const SESSION_CHECKED = 'kiban.core_checked_at';

    /**
     * The company entry of the person in the Core, or null when they do not belong to it.
     *
     * @param  array<string, mixed>  $me
     * @return array<string, mixed>|null
     */
    public function membership(array $me, Tenant $tenant): ?array
    {
        return Arr::first(
            (array) ($me['customers'] ?? []),
            fn (array $customer): bool => ($customer['uuid'] ?? null) === $tenant->uuid,
        );
    }

    /**
     * Creates or updates the local user and their role in the company (from /api/v1/me).
     *
     * @param  array<string, mixed>  $me
     * @param  array<string, mixed>  $membership
     */
    public function sync(array $me, Tenant $tenant, array $membership): User
    {
        return $this->store($me + ['role' => $membership['role'] ?? null], $tenant, $me['locale'] ?? null);
    }

    /**
     * Same, from an entry of the company's people list in the Core (id, name, email, phone, role).
     *
     * @param  array<string, mixed>  $member
     */
    public function syncMember(array $member, Tenant $tenant): User
    {
        return $this->store($member, $tenant);
    }

    public function login(User $user, Store $session): void
    {
        $session->regenerate();
        Auth::login($user, remember: true);
        $user->forceFill(['last_login_at' => now()])->save();

        $session->put(self::SESSION_CHECKED, now()->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $person
     */
    protected function store(array $person, Tenant $tenant, ?string $locale = null): User
    {
        return DB::transaction(function () use ($person, $tenant, $locale): User {
            $attributes = [
                'name' => (string) $person['name'],
                'email' => $person['email'] ?? null,
                'phone' => $person['phone'] ?? null,
            ];

            if ($locale !== null) {
                $attributes['locale'] = $locale;
            }

            $user = User::query()->updateOrCreate(['core_id' => (int) $person['id']], $attributes);

            $role = in_array($person['role'] ?? null, [User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_MEMBER], true)
                ? $person['role']
                : User::ROLE_MEMBER;

            $user->tenants()->syncWithoutDetaching([$tenant->getKey() => ['role' => $role, 'last_seen_at' => now()]]);
            $user->forgetRoles();

            return $user;
        });
    }
}
