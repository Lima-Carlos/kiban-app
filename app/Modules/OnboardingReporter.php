<?php

namespace App\Modules;

use App\Licensing\CoreClient;
use App\Licensing\CoreUnavailableException;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Tells the Kiban Core how each company is doing with the first steps ("2 of 5"), so the Kiban team
 * can see it on the customer page and offer help when it stalls. Only sends when something changed.
 */
class OnboardingReporter
{
    public function __construct(
        protected ModuleRegistry $modules,
        protected CoreClient $core,
        protected TenantContext $context,
    ) {}

    /**
     * @return int how many modules were sent to the Core
     */
    public function report(Tenant $tenant): int
    {
        return $this->context->run($tenant, function (Tenant $tenant): int {
            $reported = (array) ($tenant->onboarding_reported ?? []);
            $sent = 0;

            foreach ($this->modules->enabledFor($tenant) as $key => $module) {
                $steps = array_map(
                    fn (array $step): array => ['key' => $step['key'], 'done' => $step['done']],
                    $module->progress($tenant),
                );
                $hash = md5((string) json_encode($steps));

                if (($reported[$key] ?? null) === $hash) {
                    continue;
                }

                try {
                    $this->core->reportOnboarding($tenant->subdomain, $key, $steps);
                } catch (CoreUnavailableException $exception) {
                    Log::info('First steps not sent; will try again later.', ['tenant' => $tenant->subdomain, 'error' => $exception->getMessage()]);

                    continue;
                }

                $reported[$key] = $hash;
                $sent++;
            }

            if ($sent > 0) {
                $tenant->forceFill(['onboarding_reported' => $reported])->save();
            }

            return $sent;
        });
    }
}
