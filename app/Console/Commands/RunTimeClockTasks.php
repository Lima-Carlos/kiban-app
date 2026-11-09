<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Modules\TimeClock\Services\TimeClockCleanup;
use App\Modules\TimeClock\Services\TimeClockNotices;
use App\Modules\TimeClock\TimeClockModule;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Console\Command;

/**
 * Hourly Ponto routine for every company that has it: the month closed by itself (when the company chose a day),
 * the warnings of the bell (each one only once) and the removal of old punch photos.
 */
class RunTimeClockTasks extends Command
{
    protected $signature = 'kiban:timeclock';

    protected $description = 'Hourly Ponto routine (warnings, old punch photos)';

    public function handle(TenantContext $context): int
    {
        $notices = 0;
        $removed = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($context, &$notices, &$removed): void {
            if (! $tenant->license()->hasModule(TimeClockModule::KEY)) {
                return;
            }

            // "Yesterday" and the hour of the warnings follow the company's time zone (as on its screens).
            FilamentTimezone::set($tenant->license()->tenant('timezone') ?: config('kiban.timezone'));

            // The month closed by itself on the day the company chose (Settings of the time clock).
            $context->run($tenant, fn () => app(MonthClosing::class)->autoClose(CarbonImmutable::now(FilamentTimezone::get())));
            $notices += $context->run($tenant, fn (): int => app(TimeClockNotices::class)->scan());
            $removed += $context->run($tenant, fn (): int => app(TimeClockCleanup::class)->run());
        });

        $this->components->info(__('timeclock.console.ran', ['notices' => $notices, 'removed' => $removed]));

        return self::SUCCESS;
    }
}
