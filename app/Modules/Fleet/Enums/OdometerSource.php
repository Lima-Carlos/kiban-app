<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

enum OdometerSource: string implements HasLabel
{
    case Registration = 'registration';

    case Manual = 'manual';

    case Fuel = 'fuel';

    case Maintenance = 'maintenance';

    case Inspection = 'inspection';

    case Wash = 'wash';

    case Correction = 'correction';

    public function getLabel(): string
    {
        return __('fleet.enums.odometer_source.'.$this->value);
    }
}
