<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Services\FleetCleanup;
use App\Modules\Fleet\Services\FleetNotices;
use App\Modules\Fleet\Services\FuelService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Hourly Frota routine for every company that has it: fuel codes not finished in time become "expired",
 * the warnings of the bell (each one only once) and the removal of old photos and receipts.
 */
class RunFleetTasks extends Command
{
    protected $signature = 'kiban:fleet';

    protected $description = 'Hourly Frota routine (expire unfinished fuel codes, warnings, old files)';

    public function handle(TenantContext $context, FuelService $fuel): int
    {
        $expired = 0;
        $notices = 0;
        $removed = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($context, $fuel, &$expired, &$notices, &$removed): void {
            if (! $tenant->license()->hasModule(FleetModule::KEY)) {
                return;
            }

            $expired += $context->run($tenant, fn (): int => $fuel->expireStale());
            $notices += $context->run($tenant, fn (): int => app(FleetNotices::class)->scan());
            $removed += $context->run($tenant, fn (): int => app(FleetCleanup::class)->run());
        });

        $this->components->info(__('fleet.console.ran', ['expired' => $expired, 'notices' => $notices, 'removed' => $removed]));

        return self::SUCCESS;
    }
}
