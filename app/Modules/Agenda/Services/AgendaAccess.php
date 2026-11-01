<?php

namespace App\Modules\Agenda\Services;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\AgendaRole;
use App\Modules\Agenda\Models\Professional;
use App\People\Person;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Who the signed-in person is in the Agenda: manager, front desk, professional, viewer or none.
 * Owners and administrators are managers; a professional with a login is a professional by itself.
 */
class AgendaAccess
{
    public function __construct(protected TenantContext $context) {}

    public function role(?User $user = null): AgendaRole
    {
        $user ??= Auth::user();

        if (! $user instanceof User || $this->context->current() === null) {
            return AgendaRole::None;
        }

        return AgendaRole::tryFrom((string) app(AccessManager::class)->role($user, AgendaModule::KEY)) ?? AgendaRole::None;
    }

    public function isManager(?User $user = null): bool
    {
        return $this->role($user) === AgendaRole::Manager;
    }

    /** Manager or front desk: books, moves and cancels any appointment. */
    public function runsTheDesk(?User $user = null): bool
    {
        return $this->role($user)->runsTheDesk();
    }

    /** The active professional record of this login (a groomer who signs in), or null. */
    public function professionalFor(?User $user = null): ?Professional
    {
        $user ??= Auth::user();

        if (! $user instanceof User || $this->context->current() === null) {
            return null;
        }

        $person = Person::query()->where('user_id', $user->getKey())->first();

        return $person === null ? null : Professional::query()->active()->where('person_id', $person->getKey())->first();
    }

    /**
     * People who run the desk (managers and front desk), for the bell.
     *
     * @return Collection<int, User>
     */
    public function deskTeam(): Collection
    {
        return $this->context->get()->users()->get()
            ->filter(fn (User $user): bool => $this->role($user)->runsTheDesk())
            ->values()
            ->toBase();
    }
}
