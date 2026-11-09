<?php

namespace App\Jobs;

use App\Exports\CompanyExport;
use App\Models\Tenant;
use App\Models\User;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds the company's "download everything" file in the background and warns who asked, in the bell, with the link.
 */
class ExportCompanyData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public int $tenantId, public int $userId) {}

    public function handle(CompanyExport $export, TenantContext $context, Notifier $notifier): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $user = User::query()->find($this->userId);

        if ($tenant === null || $user === null) {
            return;
        }

        $context->run($tenant, function (Tenant $tenant) use ($export, $notifier, $user): void {
            $name = $export->build($tenant);

            $notifier->send([$user], new Notice(
                $tenant->getKey(), 'app.export.ready_title', [], 'app.export.ready_body', ['days' => CompanyExport::KEEP_DAYS],
                route('company.export', ['file' => $name]), 'heroicon-o-archive-box-arrow-down', 'success',
            ));
        });
    }
}
