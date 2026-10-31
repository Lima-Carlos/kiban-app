<?php

namespace App\People\Epi;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Replacement situation of an item with a person (by the useful life): fine, to replace soon (7 days), to
 * replace (the day came or passed), no automatic replacement, or already returned.
 */
enum LineStatus: string implements HasColor, HasLabel
{
    case Ok = 'ok';

    case Soon = 'soon';

    case Due = 'due';

    case NoLife = 'no_life';

    case Returned = 'returned';

    public function getLabel(): string
    {
        return __('epi.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Soon => 'warning',
            self::Due => 'danger',
            self::NoLife, self::Returned => 'gray',
        };
    }
}
