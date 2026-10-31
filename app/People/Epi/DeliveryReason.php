<?php

namespace App\People\Epi;

use Filament\Support\Contracts\HasLabel;

/**
 * Why an item was handed over (first delivery, wear, loss, damage, end of the useful life / validity, other).
 * The same reasons explain a return.
 */
enum DeliveryReason: string implements HasLabel
{
    case First = 'first';

    case Wear = 'wear';

    case Loss = 'loss';

    case Damage = 'damage';

    case Expiry = 'expiry';

    case Other = 'other';

    public function getLabel(): string
    {
        return __('epi.reasons.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->getLabel()])->all();
    }
}
