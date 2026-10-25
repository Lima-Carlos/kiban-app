<?php

namespace App\Modules\TimeClock\Filament\Adjustments\Pages;

use App\Modules\TimeClock\Filament\Adjustments\AdjustmentResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListAdjustments extends ListRecords
{
    protected static string $resource = AdjustmentResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('timeclock.adjustments.help');
    }
}
