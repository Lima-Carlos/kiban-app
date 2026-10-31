<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\People\Epi\EpiService;
use App\Tenancy\TenantContext;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Console\Command;

/**
 * Daily routine of "PPE and tools" (every company, every system): the bell warns who handles the PPE about
 * replacements by the useful life, CA expiring, deliveries waiting for the signature and low stock, once per
 * milestone.
 */
class RunEpiTasks extends Command
{
    protected $signature = 'kiban:epi';

    protected $description = 'Daily warnings of the PPE and tools (replacements, CA, signatures, stock)';

    public function handle(TenantContext $context): int
    {
        $notices = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($context, &$notices): void {
            if (! app(EpiService::class)->enabled($tenant)) {
                return;
            }

            // "Today" follows the company's time zone (as on its screens).
            FilamentTimezone::set($tenant->license()->tenant('timezone') ?: config('kiban.timezone'));

            $notices += $context->run($tenant, fn (): int => app(EpiService::class)->scan());
        });

        $this->components->info(__('epi.console.ran', ['notices' => $notices]));

        return self::SUCCESS;
    }
}
