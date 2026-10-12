<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

enum VehicleType: string implements HasLabel
{
    case Car = 'car';

    case Motorcycle = 'motorcycle';

    case Van = 'van';

    case Pickup = 'pickup';

    case Truck = 'truck';

    case Bus = 'bus';

    case Machine = 'machine';

    case Other = 'other';

    public function getLabel(): string
    {
        return __('fleet.enums.vehicle_type.'.$this->value);
    }
}
