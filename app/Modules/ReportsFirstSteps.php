<?php

namespace App\Modules;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

use function Illuminate\Support\defer;

/**
 * For models that complete first steps: after a change, the progress is sent to the Core once the page
 * has been answered (the person does not wait for it). Several changes in one request send only once.
 *
 * @mixin Model
 */
trait ReportsFirstSteps
{
    public static function bootReportsFirstSteps(): void
    {
        $report = function (): void {
            $tenant = app(TenantContext::class)->current();

            if ($tenant !== null) {
                defer(fn () => app(OnboardingReporter::class)->report($tenant), "first-steps-{$tenant->getKey()}");
            }
        };

        static::saved($report);
        static::deleted($report);
    }
}
