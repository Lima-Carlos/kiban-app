<?php

namespace App\Modules;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * When each system arrived in the company (the license has no dates, so the app writes down the first time it saw
 * each one) and which ones came LATER: at least LATER_DAYS after the company's first system.
 *
 * The first record of a company dates every system it has to the company's creation (a new company that starts
 * with two systems, or a company that already existed before this record, never counts as "added later"); from
 * then on, a system that shows up gets today's date.
 */
class ModuleArrivals
{
    /** A system counts as "contracted later" when it arrived at least this many days after the first one. */
    public const LATER_DAYS = 3;

    /**
     * Writes down the systems seen for the first time and returns when each one arrived.
     *
     * @return array<string, CarbonImmutable>
     */
    public function record(Tenant $tenant): array
    {
        $enabled = array_keys(app(ModuleRegistry::class)->enabledFor($tenant));
        $known = TenantModule::query()->where('tenant_id', $tenant->getKey())->pluck('first_seen_at', 'module')->all();
        $since = $known === [] ? CarbonImmutable::parse($tenant->created_at ?? now()) : CarbonImmutable::now();

        foreach (array_diff($enabled, array_keys($known)) as $module) {
            TenantModule::query()->firstOrCreate(['tenant_id' => $tenant->getKey(), 'module' => $module], ['first_seen_at' => $since]);
            $known[$module] = $since;
        }

        return array_map(fn ($date): CarbonImmutable => CarbonImmutable::parse($date), array_intersect_key($known, array_flip($enabled)));
    }

    /**
     * Systems the company has that were contracted after it already used another one.
     *
     * @return array<int, string>
     */
    public function addedLater(Tenant $tenant): array
    {
        $arrivals = $this->record($tenant);

        if (count($arrivals) < 2) {
            return [];
        }

        $first = min($arrivals);

        return array_keys(array_filter($arrivals, fn (CarbonImmutable $date): bool => $date->gte($first->addDays(self::LATER_DAYS))));
    }
}
