<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VehicleStatus: string implements HasColor, HasLabel
{
    case Active = 'active';

    case Maintenance = 'maintenance';

    case Stopped = 'stopped';

    /** Sold or out of the fleet: does not count in the plan limit and accepts no new records. */
    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return __('fleet.enums.vehicle_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Maintenance => 'warning',
            self::Stopped => 'gray',
            self::Inactive => 'danger',
        };
    }

    /** Counts in the plan limit ("vehicles"). */
    public function countsInLimit(): bool
    {
        return $this !== self::Inactive;
    }
}
