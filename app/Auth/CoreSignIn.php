<?php

namespace App\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Session\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Turns the person returned by the Core (/api/v1/me) into a signed-in user of a company,
 * and keeps the person's Core tokens in the session (encrypted) to re-check access later.
 */
class CoreSignIn
{
    public const SESSION_TOKENS = 'kiban.core_tokens';

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
     * Creates or updates the local user and their role in the company.
     *
     * @param  array<string, mixed>  $me
     * @param  array<string, mixed>  $membership
     */
    public function sync(array $me, Tenant $tenant, array $membership): User
    {
        return DB::transaction(function () use ($me, $tenant, $membership): User {
            $user = User::query()->updateOrCreate(['core_id' => (int) $me['id']], [
                'name' => (string) $me['name'],
                'email' => (string) $me['email'],
                'locale' => $me['locale'] ?? null,
            ]);

            $role = in_array($membership['role'] ?? null, [User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_MEMBER], true)
                ? $membership['role']
                : User::ROLE_MEMBER;

            $user->tenants()->syncWithoutDetaching([$tenant->getKey() => ['role' => $role, 'last_seen_at' => now()]]);
            $user->forgetRoles();

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $tokens  access_token, refresh_token, expires_in
     */
    public function login(User $user, array $tokens, Store $session): void
    {
        $session->regenerate();
        Auth::login($user);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->storeTokens($tokens, $session);
        $session->put(self::SESSION_CHECKED, now()->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    public function storeTokens(array $tokens, Store $session): void
    {
        $session->put(self::SESSION_TOKENS, Crypt::encrypt([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600) - 30)->getTimestamp(),
        ]));
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_at: int}|null
     */
    public function tokens(Store $session): ?array
    {
        $value = $session->get(self::SESSION_TOKENS);

        return is_string($value) ? Crypt::decrypt($value) : null;
    }
}
