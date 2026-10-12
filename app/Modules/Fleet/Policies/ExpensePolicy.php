<?php

namespace App\Modules\Fleet\Policies;

use App\Modules\Fleet\Enums\FleetRole;

/**
 * Other expenses (tax, insurance, fines...): money, so only managers (and viewers to look).
 */
class ExpensePolicy extends FleetPolicy
{
    protected array $viewRoles = [FleetRole::Manager, FleetRole::Viewer];
}
