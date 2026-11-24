<?php

namespace App\Modules\Pedidos\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The way of an order: new → preparing → ready → out (on the way) → delivered. A pickup order skips "out" (ready →
 * delivered when the customer takes it). Cancelled is off the way. Delivered and cancelled are final.
 */
enum OrderStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Out = 'out';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('pedidos.enums.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'danger',
            self::Preparing => 'warning',
            self::Ready => 'success',
            self::Out => 'info',
            self::Delivered => 'gray',
            self::Cancelled => 'gray',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], true);
    }

    /**
     * Statuses still in the shop or on the street.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::New->value, self::Preparing->value, self::Ready->value, self::Out->value];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status): array => [$status->value => $status->getLabel()])->all();
    }
}
