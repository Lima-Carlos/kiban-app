<?php

namespace App\Modules\Fleet\Policies;

class DriverPolicy extends FleetPolicy
{
    protected bool $hiddenFromDrivers = true;
}
