<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Services\FleetNotices;
use App\Modules\Fleet\Services\FuelService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Hourly Frota routine for every company that has it: fuel codes not finished in time become "expired",
 * and the warnings of the bell (each one only once). Photo cleanup joins this routine in part 6.
 */
class RunFleetTasks extends Command
{
    protected $signature = 'kiban:fleet';

    protected $description = 'Hourly Frota routine (expire unfinished fuel codes, warnings)';

    public function handle(TenantContext $context, FuelService $fuel): int
    {
        $expired = 0;
        $notices = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($context, $fuel, &$expired, &$notices): void {
            if (! $tenant->license()->hasModule(FleetModule::KEY)) {
                return;
            }

            $expired += $context->run($tenant, fn (): int => $fuel->expireStale());
            $notices += $context->run($tenant, fn (): int => app(FleetNotices::class)->scan());
        });

        $this->components->info(__('fleet.console.ran', ['expired' => $expired, 'notices' => $notices]));

        return self::SUCCESS;
    }
}
