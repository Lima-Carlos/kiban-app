<?php

namespace App\Notices;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sends warnings to the bell. The routines give a "once" key so the same warning never comes twice
 * (e.g. "oil change soon" for the same vehicle and the same due).
 */
class Notifier
{
    /**
     * @param  iterable<User>  $users
     */
    public function send(iterable $users, Notice $notice, ?string $once = null, ?User $except = null): void
    {
        $users = collect($users)
            ->filter(fn ($user): bool => $user instanceof User)
            ->reject(fn (User $user): bool => $except !== null && $user->is($except))
            ->unique(fn (User $user): int => $user->getKey());

        if ($users->isEmpty()) {
            return;
        }

        if ($once !== null && ! $this->firstTime($notice->tenantId, $once)) {
            return;
        }

        foreach ($users as $user) {
            $user->notify($notice);
        }
    }

    /** Remembers the key; false when it was already used. */
    public function firstTime(int $tenantId, string $key): bool
    {
        return DB::table('notice_log')->insertOrIgnore(['tenant_id' => $tenantId, 'key' => $key, 'created_at' => now()]) === 1;
    }
}
