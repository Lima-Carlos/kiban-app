<?php

namespace App\Modules\TimeClock\Filament\Employees\Pages;

use App\Modules\TimeClock\Filament\Employees\EmployeeResource;
use App\Modules\TimeClock\Services\TimeClockAccess;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('timeclock.employees.help');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label(__('timeclock.employees.add'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => app(TimeClockAccess::class)->isManager())
                ->url(fn (): string => EmployeeResource::addUrl()),
        ];
    }
}
