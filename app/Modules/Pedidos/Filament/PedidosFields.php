<?php

namespace App\Modules\Pedidos\Filament;

use App\Support\Money;
use Filament\Forms\Components\TextInput;

/** Form pieces shared by the Pedidos screens. */
class PedidosFields
{
    /** Money typed as "39,90" and kept in cents (3990). */
    public static function money(string $field): TextInput
    {
        return TextInput::make($field)
            ->numeric()
            ->minValue(0)
            ->maxValue(9_999_999)
            ->step(0.01)
            ->prefix(fn (): string => Money::symbol())
            ->formatStateUsing(fn ($state): ?string => $state === null || $state === '' ? null : number_format(((int) $state) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state): ?int => blank($state) ? 0 : (int) round((float) $state * 100));
    }
}
