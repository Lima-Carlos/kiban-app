<?php

namespace App\Modules\TimeClock\Filament\Holidays\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\TimeClock\Filament\Holidays\HolidayResource;
use App\Modules\TimeClock\Filament\Holidays\NationalHolidaysWidget;
use App\Modules\TimeClock\Models\Holiday;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Services\CountryHolidays;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class ManageHolidays extends ModuleRecordsPage
{
    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('countryHolidays')
                ->label(__('timeclock.holidays.country_bring'))
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('gray')
                ->visible(fn (): bool => strtoupper((string) TimeClockSetting::current()->country) !== 'BR')
                ->authorize(fn (): bool => auth()->user()?->can('create', Holiday::class) ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('timeclock.holidays.country_help'))
                ->action(function (): void {
                    $country = (string) TimeClockSetting::current()->country;
                    $service = app(CountryHolidays::class);
                    $added = $service->import($country, (int) now()->year) + $service->import($country, (int) now()->year + 1);

                    if ($added === 0 && $service->suggestions($country, (int) now()->year) === []) {
                        Notification::make()->warning()->title(__('timeclock.holidays.country_none'))->send();

                        return;
                    }

                    Notification::make()->success()->title(trans_choice('timeclock.holidays.country_done', $added, ['count' => $added]))->send();
                }),
            ...parent::getHeaderActions(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [NationalHolidaysWidget::class];
    }
}
