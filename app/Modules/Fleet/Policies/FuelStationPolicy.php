<?php

namespace App\Modules\Fleet\Policies;

class FuelStationPolicy extends FleetPolicy
{
    protected bool $hiddenFromDrivers = true;
}
