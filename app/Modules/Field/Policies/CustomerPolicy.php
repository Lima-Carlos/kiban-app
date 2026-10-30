<?php

namespace App\Modules\Field\Policies;

use App\Modules\Field\Enums\FieldRole;

/**
 * Customers: the manager and the service desk register and change them; the viewer only looks.
 * A customer is turned off, not deleted, once it has history (work orders, from part 3).
 */
class CustomerPolicy extends FieldPolicy
{
    protected array $editRoles = [FieldRole::Manager, FieldRole::Dispatcher];
}
