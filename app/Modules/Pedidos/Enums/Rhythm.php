<?php

namespace App\Modules\Pedidos\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a customer stands against their own rhythm (the usual days between orders): on rhythm (green), late
 * (yellow), stopped (red, well past the rhythm), or new (not enough orders to know yet).
 */
enum Rhythm: string implements HasColor, HasLabel
{
    case OnRhythm = 'green';
    case Late = 'yellow';
    case Stopped = 'red';
    case New = 'new';

    public function getLabel(): string
    {
        return __('pedidos.enums.rhythm.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OnRhythm => 'success',
            self::Late => 'warning',
            self::Stopped => 'danger',
            self::New => 'info',
        };
    }

    /** The color of the house on the map. */
    public function hex(): string
    {
        return match ($this) {
            self::OnRhythm => '#16a34a',
            self::Late => '#eab308',
            self::Stopped => '#dc2626',
            self::New => '#2563eb',
        };
    }
}
