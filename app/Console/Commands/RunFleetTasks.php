<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Services\FuelService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Hourly Frota routine for every company that has it: fuel codes not finished in time become "expired".
 * (Alerts and photo cleanup join this routine in the next parts.)
 */
class RunFleetTasks extends Command
{
    protected $signature = 'kiban:fleet';

    protected $description = 'Hourly Frota routine (expire unfinished fuel codes)';

    public function handle(TenantContext $context, FuelService $fuel): int
    {
        $expired = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($context, $fuel, &$expired): void {
            if (! $tenant->license()->hasModule(FleetModule::KEY)) {
                return;
            }

            $expired += $context->run($tenant, fn (): int => $fuel->expireStale());
        });

        $this->components->info(__('fleet.console.ran', ['expired' => $expired]));

        return self::SUCCESS;
    }
}
