<?php

namespace App\Modules\Pedidos\Enums;

use Filament\Support\Contracts\HasLabel;

/** Delivered at the customer's address, or picked up at the shop. */
enum Fulfillment: string implements HasLabel
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';

    public function getLabel(): string
    {
        return __('pedidos.enums.fulfillment.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])->all();
    }
}
