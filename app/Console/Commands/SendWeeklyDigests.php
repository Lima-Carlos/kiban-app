<?php

namespace App\Console\Commands;

use App\Digest\WeeklyDigest;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Console\Command;

/**
 * Hourly: on Monday morning (7 h in each company's time zone) the weekly summary goes to the owner and the
 * administrators (bell and e-mail through the Core), once per week.
 */
class SendWeeklyDigests extends Command
{
    protected $signature = 'kiban:weekly-digest';

    protected $description = 'Weekly summary of all systems for the owner of each company (Mondays)';

    public function handle(WeeklyDigest $digest): int
    {
        $sent = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($digest, &$sent): void {
            if (! $tenant->license()->isActive()) {
                return;
            }

            FilamentTimezone::set($tenant->license()->tenant('timezone') ?: config('kiban.timezone'));

            $sent += $digest->sendDue($tenant, CarbonImmutable::now()) ? 1 : 0;
        });

        $this->components->info(__('digest.console', ['count' => $sent]));

        return self::SUCCESS;
    }
}
