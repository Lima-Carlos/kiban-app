<?php

namespace App\Modules\Field\Services;

use App\Access\AccessManager;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\FieldModule;
use App\People\Person;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;
use Filament\Forms\Components\Select;

/**
 * The Kiban Campo part of the "People" screen: the person's role (technician, service desk, manager, viewer).
 * Only people with a login get a role; owners and administrators are always managers.
 */
class FieldPeople
{
    public function __construct(protected FieldAccess $access) {}

    /**
     * @return array<int, mixed>
     */
    public function fields(): array
    {
        return [
            Select::make('role')
                ->label(__('field.people.role'))
                ->options(collect([FieldRole::None, ...FieldRole::assignable()])->mapWithKeys(fn (FieldRole $role): array => [$role->value => $role->getLabel()])->all())
                ->default(FieldRole::None->value)
                ->helperText(__('field.people.role_help'))
                ->required(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Person $person): array
    {
        return ['role' => $person->user === null ? FieldRole::None->value : $this->access->role($person->user)->value];
    }

    /**
     * Before saving: one more technician only within the plan limit (active technicians).
     *
     * @param  array<string, mixed>  $data
     */
    public function check(?Person $person, array $data): void
    {
        if (($data['role'] ?? null) !== FieldRole::Technician->value) {
            return;
        }

        if ($person?->user !== null && $this->access->role($person->user) === FieldRole::Technician) {
            return;
        }

        $license = app(TenantContext::class)->get()->license();
        $max = $license->limitWithTolerance(FieldModule::KEY, FieldModule::LIMIT_TECHNICIANS);

        if ($max !== null && $this->access->technicians()->count() >= $max) {
            throw new PersonRuleException(__('field.errors.technician_limit', ['limit' => $license->limit(FieldModule::KEY, FieldModule::LIMIT_TECHNICIANS)]));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Person $person, array $data): void
    {
        $user = $person->user;

        if ($user === null || $user->canManageTenant()) {
            return;
        }

        $role = FieldRole::tryFrom((string) ($data['role'] ?? '')) ?? FieldRole::None;

        app(AccessManager::class)->set($user, FieldModule::KEY, $role === FieldRole::None ? null : $role->value);
    }

    public function remove(Person $person): void
    {
        if ($person->user !== null) {
            app(AccessManager::class)->set($person->user, FieldModule::KEY, null);
        }
    }

    public function roleLabel(Person $person): ?string
    {
        if ($person->user === null) {
            return null;
        }

        $role = $this->access->role($person->user);

        return $role === FieldRole::None ? null : $role->getLabel();
    }
}
