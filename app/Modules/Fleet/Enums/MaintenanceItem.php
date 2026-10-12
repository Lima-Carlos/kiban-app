<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaintenanceItem: string implements HasLabel
{
    case OilChange = 'oil_change';

    case Revision = 'revision';

    case Tires = 'tires';

    case Brakes = 'brakes';

    case Alignment = 'alignment';

    case Battery = 'battery';

    case Filters = 'filters';

    case Suspension = 'suspension';

    case Electrical = 'electrical';

    case Bodywork = 'bodywork';

    case Other = 'other';

    public function getLabel(): string
    {
        return __('fleet.enums.maintenance_item.'.$this->value);
    }
}
