<?php

namespace App\People\Epi;

use Filament\Support\Contracts\HasLabel;

/**
 * What happened to a returned item: back to the stock (reusable), discarded (written off) or lost.
 */
enum ReturnOutcome: string implements HasLabel
{
    case Stock = 'stock';

    case Discard = 'discard';

    case Lost = 'lost';

    public function getLabel(): string
    {
        return __('epi.outcomes.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $outcome): array => [$outcome->value => $outcome->getLabel()])->all();
    }
}
