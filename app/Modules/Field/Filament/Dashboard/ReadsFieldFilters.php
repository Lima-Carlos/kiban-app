<?php

namespace App\Modules\Field\Filament\Dashboard;

use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The month chosen at the top of the Campo dashboard (this month on the start page).
 */
trait ReadsFieldFilters
{
    use InteractsWithPageFilters;

    protected function month(): CarbonImmutable
    {
        $month = (string) ($this->pageFilters['month'] ?? '');

        return preg_match('/^\d{4}-\d{2}$/', $month)
            ? CarbonImmutable::parse($month.'-01')
            : CarbonImmutable::now(FilamentTimezone::get())->startOfMonth();
    }

    public static function canView(): bool
    {
        return FieldDashboard::canAccess();
    }
}
