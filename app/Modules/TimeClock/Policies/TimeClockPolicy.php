<?php

namespace App\Modules\TimeClock\Policies;

use App\Models\User;
use App\Modules\ModulePolicy;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\TimeClockModule;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base rules of the time clock screens: the manager (HR) changes, the viewer only looks.
 * Employees see only their own things (from part 3 on).
 */
abstract class TimeClockPolicy extends ModulePolicy
{
    protected string $module = TimeClockModule::KEY;

    /** @var array<int, TimeClockRole> */
    protected array $viewRoles = [TimeClockRole::Manager, TimeClockRole::Viewer];

    public function viewAny(User $user): Response
    {
        $access = parent::viewAny($user);

        if ($access->allowed() && ! in_array(app(TimeClockAccess::class)->role($user), $this->viewRoles, true)) {
            return Response::deny(__('timeclock.denied.manager'));
        }

        return $access;
    }

    public function create(User $user): Response
    {
        return $this->managerOnly($user, fn (): Response => parent::create($user));
    }

    public function update(User $user, Model $record): Response
    {
        return $this->managerOnly($user, fn (): Response => parent::update($user, $record));
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->managerOnly($user, fn (): Response => parent::delete($user, $record));
    }

    public function deleteAny(User $user): Response
    {
        return $this->managerOnly($user, fn (): Response => parent::deleteAny($user));
    }

    /**
     * @param  callable(): Response  $next
     */
    protected function managerOnly(User $user, callable $next): Response
    {
        $response = $next();

        if ($response->denied()) {
            return $response;
        }

        return app(TimeClockAccess::class)->isManager($user) ? Response::allow() : Response::deny(__('timeclock.denied.manager'));
    }
}
