<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Services\AddressLocator;
use App\Modules\Field\Services\DayWatch;
use App\Modules\Field\Models\Site;
use App\Tenancy\TenantContext;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiban Campo routine, every minute:
 * - the technicians' day (DayWatch): the alert on the phone to leave for the next service, and the office told
 *   about a risk of being late or a technician who has not arrived yet (each warning only once);
 * - addresses still without a point searched on the map (new customers, spreadsheets), a few per run and one per
 *   second at most (rule of the free OpenStreetMap service), the oldest first, whatever the company.
 */
class RunFieldTasks extends Command
{
    protected $signature = 'kiban:field {--max=40 : Addresses searched in this run}';

    protected $description = 'Kiban Campo routine (technicians\' day alerts, addresses searched on the map)';

    public function handle(TenantContext $context, AddressLocator $locator): int
    {
        $alerts = 0;

        // Only companies with work on the agenda today (a cheap look without choosing a company first).
        $busy = DB::table('fd_orders')->whereIn('status', ['scheduled', 'in_progress'])
            ->whereBetween('scheduled_at', [now()->subDay(), now()->addDay()])->distinct()->pluck('tenant_id');

        Tenant::query()->whereIn('id', $busy)->each(function (Tenant $tenant) use ($context, &$alerts): void {
            if (! $tenant->license()->hasModule(FieldModule::KEY)) {
                return;
            }

            FilamentTimezone::set($tenant->license()->tenant('timezone') ?: config('kiban.timezone'));
            $alerts += $context->run($tenant, fn (): int => app(DayWatch::class)->scan());
        });

        [$searched, $found] = $locator->enabled() ? $this->locate($context, $locator) : [0, 0];

        $this->components->info(__('field.console.ran', ['searched' => $searched, 'found' => $found, 'alerts' => $alerts]));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function locate(TenantContext $context, AddressLocator $locator): array
    {
        $pause = (int) config('kiban.field.locator_pause_ms');
        $found = 0;
        $searched = 0;

        // No company is chosen here: only the ids of the waiting addresses are read, then each one inside its company.
        $waiting = DB::table('fd_sites')->where('locate_status', Site::PENDING)->orderBy('updated_at')->limit((int) $this->option('max'))->get(['id', 'tenant_id']);
        $tenants = Tenant::query()->whereIn('id', $waiting->pluck('tenant_id')->unique())->get()->keyBy('id');

        foreach ($waiting as $row) {
            $tenant = $tenants->get($row->tenant_id);

            if ($tenant === null || ! $tenant->license()->hasModule(FieldModule::KEY)) {
                continue;
            }

            if ($searched > 0 && $pause > 0) {
                usleep($pause * 1000);
            }

            $searched++;
            $found += (int) $context->run($tenant, function () use ($locator, $row): bool {
                $site = Site::query()->find($row->id);

                return $site !== null && $site->locate_status === Site::PENDING && $locator->locate($site);
            });
        }

        return [$searched, $found];
    }
}
