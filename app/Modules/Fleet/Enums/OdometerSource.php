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

    /** Typed by a technician arriving at a work order (Kiban Campo). */
    case WorkOrder = 'work_order';

    public function getLabel(): string
    {
        return __('fleet.enums.odometer_source.'.$this->value);
    }
}
