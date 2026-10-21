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

    /** A critical item failed in an inspection: the vehicle went to "in maintenance". */
    case StoppedByInspection = 'stopped_by_inspection';

    public function getLabel(): string
    {
        return __('fleet.enums.vehicle_event.'.$this->value);
    }
}
