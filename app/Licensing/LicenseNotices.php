<?php

namespace App\Licensing;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Filament\Support\Facades\FilamentTimezone;

/**
 * Warnings shown at the top of every screen: module in read-only mode, trial ending,
 * and the Core unreachable for so long that the license is about to expire.
 */
class LicenseNotices
{
    public function __construct(protected ModuleRegistry $modules, protected TrialBanner $trial) {}

    /**
     * @return array<int, array{level: string, text: string}>
     */
    public function for(Tenant $tenant): array
    {
        $license = $tenant->license();
        $notices = [];
        $format = __('app.datetime_format');
        $timezone = FilamentTimezone::get();
        $user = auth()->user();
        // The trial is a commercial message: only for who manages the company, and not twice when the
        // "free trial" strip is already at the top (every system in trial).
        $trialNotices = $user instanceof User && $user->canManageTenant() && $this->trial->trialEndsAt($tenant) === null;

        foreach ($this->modules->enabledFor($tenant) as $key => $module) {
            if ($license->isReadOnly($key)) {
                $notices[] = ['level' => 'danger', 'text' => __('app.license.read_only', ['module' => $module->name()])];
            }

            $trialEnds = $license->trialEndsAt($key);

            if ($trialNotices && $trialEnds !== null && $trialEnds->isFuture() && $trialEnds->lt(now()->addDays(7))) {
                $notices[] = ['level' => 'warning', 'text' => __('app.license.trial', ['module' => $module->name(), 'date' => $trialEnds->setTimezone($timezone)->translatedFormat(__('app.date_format'))])];
            }
        }

        $expires = $license->expiresAt();

        if ($tenant->license_failed_at !== null && $expires !== null && $expires->lt(now()->addHours((int) config('kiban.license.warn_hours')))) {
            $notices[] = ['level' => 'danger', 'text' => __('app.license.offline', ['date' => $expires->setTimezone($timezone)->translatedFormat($format)])];
        }

        return $notices;
    }
}
