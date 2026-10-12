<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Warnings found when a fuel-up is finished. They do not block: the manager looks at them.
 */
enum FuelUpFlag: string implements HasLabel
{
    /** More fuel than the tank holds. */
    case OverCapacity = 'over_capacity';

    /** Fuel the vehicle does not use (e.g. diesel in a flex car). */
    case WrongFuel = 'wrong_fuel';

    /** The vehicle used much more fuel than its average: possible mechanical problem (or fuel leaving the tank). */
    case HighConsumption = 'high_consumption';

    /** Fueled by someone not linked to this vehicle (reason informed). */
    case OtherVehicle = 'other_vehicle';

    /** Finished after the code expired. */
    case Late = 'late';

    public function getLabel(): string
    {
        return __('fleet.enums.fuel_flag.'.$this->value);
    }
}
