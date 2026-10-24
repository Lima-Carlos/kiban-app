<?php

namespace App\Modules\TimeClock\Filament\Holidays;

use App\Modules\TimeClock\Filament\TimeClockSettingsPage;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Services\HolidayCalendar;
use Filament\Widgets\Widget;

/**
 * The national holidays of the year (calculated: nothing to register) and the optional days off of the
 * company (Good Friday, Carnival, Corpus Christi), on top of the company's own holidays.
 */
class NationalHolidaysWidget extends Widget
{
    protected string $view = 'timeclock.national-holidays';

    protected int|string|array $columnSpan = 'full';

    public int $year;

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    public function previousYear(): void
    {
        $this->year--;
    }

    public function nextYear(): void
    {
        $this->year++;
    }

    /**
     * @return array<string, string>
     */
    public function holidays(): array
    {
        return app(HolidayCalendar::class)->national($this->year);
    }

    public function isBrazil(): bool
    {
        return strtoupper((string) TimeClockSetting::current()->country) === 'BR';
    }

    public function settingsUrl(): string
    {
        return TimeClockSettingsPage::getUrl();
    }
}
