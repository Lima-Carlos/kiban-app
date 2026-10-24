<?php

namespace App\Modules\TimeClock\Policies;

use App\Models\User;
use App\Modules\TimeClock\Models\Workplace;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Workplaces: the manager registers them; a place with employees is turned off, not deleted.
 */
class WorkplacePolicy extends TimeClockPolicy
{
    public function delete(User $user, Model $record): Response
    {
        /** @var Workplace $record */
        if ($record->employees()->exists()) {
            return Response::deny(__('timeclock.denied.workplace_in_use'));
        }

        return parent::delete($user, $record);
    }
}
