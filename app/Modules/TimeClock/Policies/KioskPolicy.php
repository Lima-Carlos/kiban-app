<?php

namespace App\Modules\TimeClock\Policies;

use App\Models\User;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Models\Kiosk;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Time clock tablets: only the manager. A tablet with punches is deactivated, not deleted.
 */
class KioskPolicy extends TimeClockPolicy
{
    protected array $viewRoles = [TimeClockRole::Manager];

    public function delete(User $user, Model $record): Response
    {
        /** @var Kiosk $record */
        return $record->punches()->exists() ? Response::deny(__('timeclock.denied.history_locked')) : parent::delete($user, $record);
    }
}
