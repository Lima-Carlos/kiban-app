<?php

namespace App\Modules\TimeClock\Policies;

use App\Models\User;
use App\Modules\TimeClock\Models\Schedule;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Work schedules: the manager registers them. One already given to someone is not changed or deleted
 * (it would change past days): it is duplicated and the new one is given from a date.
 */
class SchedulePolicy extends TimeClockPolicy
{
    public function update(User $user, Model $record): Response
    {
        /** @var Schedule $record */
        return $record->isInUse() ? Response::deny(__('timeclock.schedules.in_use_help')) : parent::update($user, $record);
    }

    public function delete(User $user, Model $record): Response
    {
        /** @var Schedule $record */
        return $record->isInUse() ? Response::deny(__('timeclock.schedules.in_use_help')) : parent::delete($user, $record);
    }
}
