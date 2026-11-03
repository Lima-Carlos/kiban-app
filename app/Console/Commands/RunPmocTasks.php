<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Field\FieldModule;
use App\Modules\Pmoc\PmocModule;
use App\Modules\Pmoc\Services\PmocService;
use App\Tenancy\TenantContext;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Console\Command;

/**
 * Daily routine of the PMOC: for every company with the PMOC and the Campo, opens the work orders of the visits
 * that are due within the plan's lead days (in the company's language and time zone).
 */
class RunPmocTasks extends Command
{
    protected $signature = 'kiban:pmoc';

    protected $description = 'Open the work orders of the preventive maintenance visits that are due';

    public function handle(TenantContext $context): int
    {
        $opened = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($context, &$opened): void {
            $license = $tenant->license();

            if (! $license->hasModule(PmocModule::KEY) || ! $license->hasModule(FieldModule::KEY) || $license->isReadOnly(PmocModule::KEY)) {
                return;
            }

            FilamentTimezone::set($license->tenant('timezone') ?: config('kiban.timezone'));
            $locale = app()->getLocale();
            app()->setLocale((string) ($license->tenant('locale') ?: config('app.locale')));

            try {
                $opened += $context->run($tenant, fn (): int => app(PmocService::class)->openDueVisits());
            } finally {
                app()->setLocale($locale);
            }
        });

        $this->components->info(__('pmoc.console.ran', ['visits' => $opened]));

        return self::SUCCESS;
    }
}
