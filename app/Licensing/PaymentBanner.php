<?php

namespace App\Licensing;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * "Your invoice is due in N days / today / overdue" strip for the owner and administrators, from 3 days before the
 * due date of the oldest unpaid invoice (the date comes in the license, claim "billing"). Nothing for employees.
 */
class PaymentBanner
{
    /** Days before the due date when the strip starts to show. */
    public const DAYS_BEFORE = 3;

    /**
     * @return array{days: int, overdue: bool, due_on: CarbonImmutable, url: string}|null
     */
    public function for(Tenant $tenant, ?User $user): ?array
    {
        if ($user === null || ! $user->canManageTenant()) {
            return null;
        }

        $billing = (array) ($tenant->license()->claims['billing'] ?? []);

        if (blank($billing['due_on'] ?? null)) {
            return null;
        }

        $timezone = $tenant->license()->tenant('timezone') ?: config('kiban.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $dueOn = CarbonImmutable::parse((string) $billing['due_on'], $timezone)->startOfDay();
        $days = (int) $today->diffInDays($dueOn, false);

        if ($days > self::DAYS_BEFORE) {
            return null;
        }

        return [
            'days' => max(0, $days),
            'overdue' => $days < 0,
            'due_on' => $dueOn,
            'url' => config('kiban.core.url').'/conta/faturas',
        ];
    }
}
