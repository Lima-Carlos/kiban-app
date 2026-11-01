<?php

namespace App\Modules\Agenda\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Size of what is attended, for prices and times (pets: P, M, G, GG; later cars: hatch, sedan, SUV...).
 */
enum ItemSize: string implements HasLabel
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case XLarge = 'xlarge';

    public function getLabel(): string
    {
        return __('agenda.enums.size.'.$this->value);
    }

    /** "P", "M", "G", "GG". */
    public function short(): string
    {
        return __('agenda.enums.size_short.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $size): array => [$size->value => $size->getLabel()])->all();
    }
}
