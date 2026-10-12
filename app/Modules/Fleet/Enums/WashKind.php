<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

enum WashKind: string implements HasLabel
{
    case Simple = 'simple';

    case Complete = 'complete';

    public function getLabel(): string
    {
        return __('fleet.enums.wash_kind.'.$this->value);
    }
}
