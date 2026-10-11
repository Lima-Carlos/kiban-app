<?php

namespace App\Console\Commands;

use App\Licensing\LicenseManager;
use App\Models\Tenant;
use App\Modules\OnboardingReporter;
use Illuminate\Console\Command;

/**
 * Every 5 minutes: new license for each company and first-steps progress sent to the Core.
 * If the Core is down, nothing breaks: companies keep the last license until it expires.
 */
class RefreshLicenses extends Command
{
    protected $signature = 'kiban:licenses';

    protected $description = 'Refresh company licenses and send first-steps progress to the Kiban Core';

    public function handle(LicenseManager $licenses, OnboardingReporter $onboarding): int
    {
        $ok = 0;
        $failed = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($licenses, $onboarding, &$ok, &$failed): void {
            if (! $licenses->refresh($tenant)) {
                $failed++;

                return;
            }

            $ok++;

            if ($tenant->license()->isActive()) {
                $onboarding->report($tenant);
            }
        });

        $this->components->info(__('app.console.licenses', ['ok' => $ok, 'failed' => $failed]));

        return self::SUCCESS;
    }
}
