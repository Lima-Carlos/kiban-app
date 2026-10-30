<?php

namespace App\Modules\Field\Policies;

/**
 * Types of service: the manager sets them up; the service desk and the viewer see them.
 */
class ServiceTypePolicy extends FieldPolicy
{
    protected array $requiresSteps = ['business'];
}
