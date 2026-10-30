<?php

namespace App\Modules\Field\Services;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\FieldModule;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Who the signed-in person is in Kiban Campo: manager, service desk, technician, viewer or none.
 * Owners and administrators are managers; the others get a role in the People screen (or an access profile).
 */
class FieldAccess
{
    public function __construct(protected TenantContext $context) {}

    public function role(?User $user = null): FieldRole
    {
        $user ??= Auth::user();

        if (! $user instanceof User || $this->context->current() === null) {
            return FieldRole::None;
        }

        return FieldRole::tryFrom((string) app(AccessManager::class)->role($user, FieldModule::KEY)) ?? FieldRole::None;
    }

    public function isManager(?User $user = null): bool
    {
        return $this->role($user) === FieldRole::Manager;
    }

    public function managesCustomers(?User $user = null): bool
    {
        return $this->role($user)->managesCustomers();
    }

    /**
     * Technicians of the company (they get work orders on the agenda), by name.
     *
     * @return Collection<int, User>
     */
    public function technicians(): Collection
    {
        return $this->context->get()->users()->get()
            ->filter(fn (User $user): bool => $this->role($user) === FieldRole::Technician)
            ->sortBy('name')
            ->values()
            ->toBase();
    }

    public function isTechnician(User $user): bool
    {
        return $user->belongsToTenant($this->context->get()) && $this->role($user) === FieldRole::Technician;
    }
}
