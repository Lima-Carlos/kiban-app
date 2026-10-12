<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Role of a person in the Frota. Owners and administrators of the company are always managers.
 */
enum FleetRole: string implements HasColor, HasLabel
{
    /** Sees and changes everything. */
    case Manager = 'manager';

    /** Registers the services done (Part 3); sees vehicles, not costs dashboards or reports. */
    case Mechanic = 'mechanic';

    /** Linked to a driver record: only their vehicles, fuel-ups and inspections, on the phone. */
    case Driver = 'driver';

    /** Only looks. */
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return __('fleet.enums.role.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manager => 'primary',
            self::Mechanic => 'warning',
            self::Driver => 'success',
            self::Viewer => 'gray',
        };
    }

    /**
     * Roles a manager can give in the team screen (drivers come from the driver records).
     *
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return [self::Manager, self::Mechanic, self::Viewer];
    }
}
