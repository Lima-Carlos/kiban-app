<?php

namespace App\Modules\Field\Policies;

use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Work orders: the manager and the service desk open, schedule and cancel them; the viewer only looks.
 * The technician gets their own screen on the phone (part 4). Orders are never deleted: they are canceled.
 */
class WorkOrderPolicy extends FieldPolicy
{
    protected array $editRoles = [FieldRole::Manager, FieldRole::Dispatcher];

    protected array $requiresSteps = ['service_types'];

    public function delete(User $user, Model $record): Response
    {
        return Response::deny(__('field.denied.order_delete'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('field.denied.order_delete'));
    }
}
