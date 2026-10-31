<?php

namespace App\Licensing;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;

/**
 * The "free trial: N days left · See plans" strip at the top of the company's screens.
 *
 * Shown only while every system of the company is still in its free trial (once one is paid, the trial
 * is no longer "the company's trial") and only to who manages the company (owner or administrator):
 * employees, drivers and technicians never see commercial messages.
 */
class TrialBanner
{
    /** In the last days the strip uses the warning colour. */
    public const URGENT_DAYS = 3;

    public function __construct(protected ModuleRegistry $modules) {}

    /**
     * @return array{days: int, ends_at: CarbonImmutable, urgent: bool, url: string}|null
     */
    public function for(Tenant $tenant, ?User $user): ?array
    {
        if ($user === null || ! $user->canManageTenant()) {
            return null;
        }

        $endsAt = $this->trialEndsAt($tenant);

        if ($endsAt === null) {
            return null;
        }

        $timezone = $tenant->license()->tenant('timezone') ?: config('kiban.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $lastDay = $endsAt->setTimezone($timezone)->startOfDay();
        $days = max(0, (int) $today->diffInDays($lastDay, false));

        return [
            'days' => $days,
            'ends_at' => $endsAt,
            'urgent' => $days <= self::URGENT_DAYS,
            'url' => config('kiban.core.url').'/conta/sistemas',
        ];
    }

    /**
     * When the company's trial ends: the earliest end among its systems, or null when at least one
     * system is not in trial (already paid, blocked...) or there is no system at all.
     */
    public function trialEndsAt(Tenant $tenant): ?CarbonImmutable
    {
        $license = $tenant->license();
        $ends = [];

        foreach (array_keys($this->modules->enabledFor($tenant)) as $key) {
            if (($license->module($key)['status'] ?? null) !== 'trial') {
                return null;
            }

            $date = $license->trialEndsAt($key);

            if ($date === null) {
                return null;
            }

            $ends[] = $date;
        }

        return $ends === [] ? null : collect($ends)->sort()->first();
    }
}
