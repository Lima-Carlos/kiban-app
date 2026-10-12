<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

enum VehicleEventType: string implements HasLabel
{
    case Created = 'created';

    case IdentityChanged = 'identity_changed';

    case Deactivated = 'deactivated';

    case Reactivated = 'reactivated';

    case OdometerCorrected = 'odometer_corrected';

    public function getLabel(): string
    {
        return __('fleet.enums.vehicle_event.'.$this->value);
    }
}
