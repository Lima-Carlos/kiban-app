<?php

namespace App\People\Epi;

use Filament\Support\Contracts\HasLabel;

/**
 * Kind of item: personal protective equipment (PPE, NR-6 in Brazil) or a tool / equipment / uniform.
 */
enum EpiKind: string implements HasLabel
{
    case Epi = 'epi';

    case Tool = 'tool';

    public function getLabel(): string
    {
        return __('epi.kinds.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(?array $only = null): array
    {
        return collect(self::cases())
            ->filter(fn (self $kind): bool => $only === null || in_array($kind->value, $only, true))
            ->mapWithKeys(fn (self $kind): array => [$kind->value => $kind->getLabel()])
            ->all();
    }
}
