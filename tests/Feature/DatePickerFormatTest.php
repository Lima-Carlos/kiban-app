<?php

namespace Tests\Feature;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Tests\TestCase;

/**
 * Date and time fields follow the language of the account (never the browser's): "01/11/2026" and "18:05" in
 * Portuguese and Spanish, "11/01/2026" and "6:05 PM" in English (AppServiceProvider::configureDatePickers).
 */
class DatePickerFormatTest extends TestCase
{
    public function test_pickers_use_the_formats_of_the_language(): void
    {
        $expected = [
            'pt_BR' => ['d/m/Y', 'H:i', 'd/m/Y H:i'],
            'es' => ['d/m/Y', 'H:i', 'd/m/Y H:i'],
            'en' => ['m/d/Y', 'g:i A', 'm/d/Y g:i A'],
        ];

        foreach ($expected as $locale => [$date, $time, $dateTime]) {
            app()->setLocale($locale);
            $datePicker = DatePicker::make('day');
            $timePicker = TimePicker::make('time')->seconds(false);
            $dateTimePicker = DateTimePicker::make('at')->seconds(false);

            $this->assertFalse($datePicker->isNative(), $locale);
            $this->assertFalse($timePicker->isNative(), $locale);
            $this->assertSame([$date, $time, $dateTime], [$datePicker->getDisplayFormat(), $timePicker->getDisplayFormat(), $dateTimePicker->getDisplayFormat()], $locale);
            $this->assertSame($locale, $datePicker->getLocale());
        }

        // Read when the field is drawn (after the language of the request is known), not when it is made.
        app()->setLocale('en');
        $picker = DatePicker::make('day');
        app()->setLocale('pt_BR');
        $this->assertSame('d/m/Y', $picker->getDisplayFormat());

        // A format chosen by the screen itself still wins.
        $this->assertSame('m/Y', DatePicker::make('month')->displayFormat('m/Y')->getDisplayFormat());
        $this->assertSame('H:i:s', TimePicker::make('time')->getDisplayFormat());
    }
}
