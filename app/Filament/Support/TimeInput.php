<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * A clock hour typed straight into the field: the person types only the numbers ("0800") and the field writes
 * "08:00". Replaces the small clock picker with arrows (slow and fiddly, mostly on the computer). The value is the
 * same "H:i" text the picker used to give, so nothing else changes.
 */
class TimeInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->mask('99:99')
            ->placeholder('08:00')
            ->inputMode('numeric')
            ->maxLength(5)
            ->extraInputAttributes(['autocomplete' => 'off'])
            ->formatStateUsing(fn ($state): ?string => filled($state) ? substr((string) $state, 0, 5) : null)
            ->rule('regex:/^([01]\d|2[0-3]):[0-5]\d$/')
            ->validationMessages(['regex' => __('app.time_invalid')]);
    }
}
