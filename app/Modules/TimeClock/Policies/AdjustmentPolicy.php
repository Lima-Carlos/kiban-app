<?php

namespace App\Modules\TimeClock\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Corrections: the manager reviews; the viewer looks. They are asked and made in "My time sheet" /
 * "Calculation" and never deleted (they are the history of the day).
 */
class AdjustmentPolicy extends TimeClockPolicy
{
    public function create(User $user): Response
    {
        return Response::deny(__('timeclock.denied.adjust_screen'));
    }

    public function delete(User $user, Model $record): Response
    {
        return Response::deny(__('timeclock.denied.history_locked'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('timeclock.denied.history_locked'));
    }
}
