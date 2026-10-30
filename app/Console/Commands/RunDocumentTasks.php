<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\People\Documents\PersonDocumentService;
use App\Tenancy\TenantContext;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Console\Command;

/**
 * Daily routine of the people's documents (every company, every system): the bell warns who takes care of the
 * documents at each milestone (warning days, 7 days before, the expiry day), once per milestone.
 */
class RunDocumentTasks extends Command
{
    protected $signature = 'kiban:documents';

    protected $description = 'Daily warnings of the people\'s documents (driver\'s license, medical exam, trainings)';

    public function handle(TenantContext $context): int
    {
        $notices = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($context, &$notices): void {
            if (! $tenant->license()->isActive()) {
                return;
            }

            // "Today" follows the company's time zone (as on its screens).
            FilamentTimezone::set($tenant->license()->tenant('timezone') ?: config('kiban.timezone'));

            $notices += $context->run($tenant, fn (): int => app(PersonDocumentService::class)->scan());
        });

        $this->components->info(__('documents.console.ran', ['notices' => $notices]));

        return self::SUCCESS;
    }
}
