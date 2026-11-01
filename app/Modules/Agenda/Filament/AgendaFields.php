<?php

namespace App\Modules\Agenda\Filament;

use App\Modules\Agenda\Models\AgendaSetting;
use Filament\Forms\Components\TextInput;

/**
 * Form fields shared by the Agenda screens.
 */
class AgendaFields
{
    /** A price typed in the company's currency (e.g. 65,00) and kept in cents. Empty = no price. */
    public static function price(string $field = 'price_cents'): TextInput
    {
        return TextInput::make($field)
            ->numeric()
            ->minValue(0)
            ->maxValue(9_999_999)
            ->step(0.01)
            ->prefix(fn (): string => AgendaSetting::current()->currency)
            ->formatStateUsing(fn ($state): ?string => $state === null || $state === '' ? null : number_format(((int) $state) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state): ?int => blank($state) ? null : (int) round((float) $state * 100));
    }

    /** A percentage (e.g. 30 or 12,5) kept in hundredths (3000, 1250). */
    public static function percent(string $field = 'commission_bp'): TextInput
    {
        return TextInput::make($field)
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->step(0.01)
            ->suffix('%')
            ->formatStateUsing(fn ($state): ?string => $state === null || $state === '' ? null : rtrim(rtrim(number_format(((int) $state) / 100, 2, '.', ''), '0'), '.'))
            ->dehydrateStateUsing(fn ($state): ?int => blank($state) ? null : (int) round((float) $state * 100));
    }
}
