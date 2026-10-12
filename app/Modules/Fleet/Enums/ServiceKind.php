<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

enum ServiceKind: string implements HasLabel
{
    case Maintenance = 'maintenance';

    case Wash = 'wash';

    public function getLabel(): string
    {
        return __('fleet.enums.service_kind.'.$this->value);
    }
}
