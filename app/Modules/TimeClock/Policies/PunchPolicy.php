<?php

namespace App\Modules\TimeClock\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Punches: the manager and the viewer see all. They are made on the phone ("Clock in") or on the tablet,
 * never on this list, and they never change or go away.
 */
class PunchPolicy extends TimeClockPolicy
{
    public function create(User $user): Response
    {
        return Response::deny(__('timeclock.denied.punch_screen'));
    }

    public function update(User $user, Model $record): Response
    {
        return Response::deny(__('timeclock.denied.history_locked'));
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
