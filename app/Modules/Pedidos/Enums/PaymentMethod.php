<?php

namespace App\Modules\Pedidos\Enums;

use Filament\Support\Contracts\HasLabel;

/** How the customer pays (only written down: no payment goes through the system in this version). */
enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Pix = 'pix';
    case Card = 'card';
    case App = 'app';

    public function getLabel(): string
    {
        return __('pedidos.enums.payment.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])->all();
    }
}
