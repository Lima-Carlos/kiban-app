<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Services\AddressLocator;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiban Campo routine, every minute: searches on the map the addresses still without a point (new customers,
 * spreadsheets), a few per run and one per second at most (rule of the free OpenStreetMap service).
 * The oldest waiting addresses go first, whatever the company.
 */
class RunFieldTasks extends Command
{
    protected $signature = 'kiban:field {--max=40 : Addresses searched in this run}';

    protected $description = 'Kiban Campo routine (addresses searched on the map)';

    public function handle(TenantContext $context, AddressLocator $locator): int
    {
        if (! $locator->enabled()) {
            return self::SUCCESS;
        }

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

        $this->components->info(__('field.console.ran', ['searched' => $searched, 'found' => $found]));

        return self::SUCCESS;
    }
}
