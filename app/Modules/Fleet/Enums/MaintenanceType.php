<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaintenanceType: string implements HasLabel
{
    case Preventive = 'preventive';

    case Corrective = 'corrective';

    public function getLabel(): string
    {
        return __('fleet.enums.maintenance_type.'.$this->value);
    }
}
