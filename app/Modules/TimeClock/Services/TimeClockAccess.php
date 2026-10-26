<?php

namespace App\Modules\TimeClock\Services;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\TimeClockModule;
use App\People\Person;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Who the signed-in person is in the time clock: manager (HR), employee, viewer or none.
 * Owners and administrators are managers; others get a role in the People screen (or an access profile);
 * someone with a login who clocks in is an employee.
 */
class TimeClockAccess
{
    public function __construct(protected TenantContext $context) {}

    public function role(?User $user = null): TimeClockRole
    {
        $user ??= Auth::user();

        if (! $user instanceof User || $this->context->current() === null) {
            return TimeClockRole::None;
        }

        return TimeClockRole::tryFrom((string) app(AccessManager::class)->role($user, TimeClockModule::KEY)) ?? TimeClockRole::None;
    }

    public function isManager(?User $user = null): bool
    {
        return $this->role($user) === TimeClockRole::Manager;
    }

    /**
     * People of the company with one of these time clock roles (owners and administrators are managers).
     *
     * @param  array<int, TimeClockRole>  $roles
     * @return Collection<int, User>
     */
    public function people(array $roles): Collection
    {
        return $this->context->get()->users()->get()
            ->filter(fn (User $user): bool => in_array($this->role($user), $roles, true))
            ->values()
            ->toBase();
    }

    /** The active employee record of this login, if they clock in. */
    public function employeeFor(User $user): ?Employee
    {
        $person = Person::query()->where('user_id', $user->getKey())->first();

        return $person === null ? null : Employee::query()->active()->where('person_id', $person->getKey())->first();
    }
}
