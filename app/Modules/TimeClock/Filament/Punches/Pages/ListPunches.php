<?php

namespace App\Modules\TimeClock\Filament\Punches\Pages;

use App\Modules\TimeClock\Filament\Punches\PunchResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListPunches extends ListRecords
{
    protected static string $resource = PunchResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('timeclock.punches.help');
    }
}
