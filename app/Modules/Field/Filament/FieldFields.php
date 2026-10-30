<?php

namespace App\Modules\Field\Filament;

use App\Modules\Field\Models\FieldSetting;
use Filament\Forms\Components\TextInput;

/**
 * Form fields shared by the Kiban Campo screens.
 */
class FieldFields
{
    /** A price typed in the company's currency (e.g. 150,00) and kept in cents. Empty = no price. */
    public static function price(string $field = 'price_cents'): TextInput
    {
        return TextInput::make($field)
            ->numeric()
            ->minValue(0)
            ->maxValue(9_999_999)
            ->step(0.01)
            ->prefix(fn (): string => FieldSetting::current()->currency)
            ->formatStateUsing(fn ($state): ?string => $state === null || $state === '' ? null : number_format(((int) $state) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state): ?int => blank($state) ? null : (int) round((float) $state * 100));
    }
}
