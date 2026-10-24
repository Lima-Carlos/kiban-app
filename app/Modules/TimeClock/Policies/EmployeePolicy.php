<?php

namespace App\Modules\TimeClock\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Employees: added in the People screen; here the manager adjusts the time clock data.
 * They are never deleted (history), only deactivated.
 */
class EmployeePolicy extends TimeClockPolicy
{
    public function delete(User $user, Model $record): Response
    {
        return Response::deny(__('timeclock.denied.history_locked'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('timeclock.denied.history_locked'));
    }
}
