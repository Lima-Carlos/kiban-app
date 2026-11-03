<?php

namespace Tests\Feature;

use App\Modules\TimeClock\Enums\LaborProfile;
use App\Modules\TimeClock\Filament\TimeClockSettingsPage;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Services\HolidayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ponto for Mexico (Ley Federal del Trabajo): the law's floors, night 20:00-06:00, no hour bank and the national
 * holidays calculated (fixed days, three Mondays and the inauguration every six years).
 */
class TimeClockMexicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mexican_company_gets_the_law_values(): void
    {
        $company = $this->tenant('taller', ['ponto' => []], ['country' => 'MX', 'locale' => 'es', 'timezone' => 'America/Mexico_City']);
        $this->signIn($company);

        $settings = TimeClockSetting::current();
        $this->assertSame(LaborProfile::MexicoLft, $settings->labor_profile);
        $this->assertSame([100, 200, '20:00', '06:00', false, false], [$settings->overtime_percent, $settings->rest_day_overtime_percent, $settings->night_start, $settings->night_end, $settings->night_reduced_hour, $settings->hour_bank]);

        // Below the law is refused; above it is fine. The hour bank and the night hours stay the law's.
        Livewire::test(TimeClockSettingsPage::class)
            ->fillForm(['overtime_percent' => 50])
            ->call('save')
            ->assertHasFormErrors(['overtime_percent']);

        Livewire::test(TimeClockSettingsPage::class)
            ->fillForm(['overtime_percent' => 120, 'night_start' => '22:00', 'hour_bank' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = TimeClockSetting::current()->fresh();
        $this->assertSame([120, '20:00', false], [$settings->overtime_percent, $settings->night_start, $settings->hour_bank]);
    }

    public function test_mexican_national_holidays(): void
    {
        $company = $this->tenant('taller', ['ponto' => []], ['country' => 'MX']);
        $this->signIn($company);
        $calendar = app(HolidayCalendar::class);

        $this->assertSame([
            '2027-01-01', '2027-02-01', '2027-03-15', '2027-05-01', '2027-09-16', '2027-11-15', '2027-12-25',
        ], array_keys($calendar->national(2027)));
        // The inauguration day, every six years.
        $this->assertArrayHasKey('2030-10-01', $calendar->national(2030));
        $this->assertSame(__('timeclock.holidays.national.mx_independence'), $calendar->holidayOn(CarbonImmutable::parse('2027-09-16')));
    }

    public function test_the_profile_follows_the_country(): void
    {
        $this->assertSame(LaborProfile::MexicoLft, LaborProfile::forCountry('mx'));
        $this->assertSame(LaborProfile::BrazilClt, LaborProfile::forCountry('BR'));
        $this->assertSame(LaborProfile::Custom, LaborProfile::forCountry('PT'));
        $this->assertFalse(LaborProfile::MexicoLft->allowsHourBank());

        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            foreach (['enums.labor_profile.mx_lft', 'enums.labor_profile_help.mx_lft', 'settings.calculation_help_mx', 'holidays.national.mx_juarez', 'holidays.national.mx_inauguration'] as $key) {
                $this->assertNotSame('timeclock.'.$key, __('timeclock.'.$key), $locale.' '.$key);
            }
        }
    }
}
