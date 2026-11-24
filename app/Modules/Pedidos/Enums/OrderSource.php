<?php

namespace App\Modules\Pedidos\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where the order came from. Orders of iFood and other apps are only written down for the customer's history
 * (the app keeps its own order); nothing is sent to them.
 */
enum OrderSource: string implements HasLabel
{
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case Counter = 'counter';
    case App = 'app';

    public function getLabel(): string
    {
        return __('pedidos.enums.source.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])->all();
    }
}
