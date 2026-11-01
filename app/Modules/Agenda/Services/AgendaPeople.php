<?php

namespace App\Modules\Agenda\Services;

use App\Access\AccessManager;
use App\Models\ModuleAccess;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\AgendaRole;
use App\Modules\Agenda\Models\Professional;
use App\People\PeopleForm;
use App\People\Person;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

/**
 * The Agenda part of the "People" screen: whether the person attends on the agenda (a professional, with or
 * without a login) and, for people with a login, their role (front desk, manager, viewer). A new professional only
 * within the plan limit (active professionals). Hours, services and commission are set in "Professionals".
 */
class AgendaPeople
{
    public function __construct(protected AgendaAccess $access) {}

    /**
     * @return array<int, mixed>
     */
    public function fields(): array
    {
        return [
            Toggle::make('attends')
                ->label(__('agenda.people.attends'))
                ->helperText(__('agenda.people.attends_help')),
            Select::make('role')
                ->label(__('agenda.people.role'))
                ->helperText(__('agenda.people.role_help'))
                ->options(collect([AgendaRole::None, AgendaRole::Desk, AgendaRole::Manager, AgendaRole::Viewer])->mapWithKeys(fn (AgendaRole $role): array => [$role->value => $role->getLabel()])->all())
                ->default(AgendaRole::None->value)
                ->visible(PeopleForm::hasLogin()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Person $person): array
    {
        $role = $person->user ? ModuleAccess::query()->where('user_id', $person->user_id)->where('module', AgendaModule::KEY)->value('role') : null;

        return [
            'attends' => Professional::query()->active()->where('person_id', $person->getKey())->exists(),
            'role' => in_array($role, [AgendaRole::Desk->value, AgendaRole::Manager->value, AgendaRole::Viewer->value], true) ? $role : AgendaRole::None->value,
        ];
    }

    /**
     * Before saving: one more professional only within the plan limit.
     *
     * @param  array<string, mixed>  $data
     */
    public function check(?Person $person, array $data): void
    {
        if (! ($data['attends'] ?? false)) {
            return;
        }

        if ($person !== null && Professional::query()->active()->where('person_id', $person->getKey())->exists()) {
            return;
        }

        $license = app(TenantContext::class)->get()->license();
        $max = $license->limitWithTolerance(AgendaModule::KEY, AgendaModule::LIMIT_PROFESSIONALS);

        if ($max !== null && Professional::query()->active()->count() >= $max) {
            throw new PersonRuleException(__('agenda.errors.professional_limit', ['limit' => $license->limit(AgendaModule::KEY, AgendaModule::LIMIT_PROFESSIONALS)]));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Person $person, array $data): void
    {
        $professional = Professional::query()->where('person_id', $person->getKey())->first();

        if ($data['attends'] ?? false) {
            if ($professional === null) {
                Professional::create([
                    'person_id' => $person->getKey(),
                    'week' => WorkingHours::defaultWeek(),
                    'sort' => (int) Professional::query()->max('sort') + 1,
                ]);
            } elseif (! $professional->is_active) {
                $professional->update(['is_active' => true]);
            }
        } elseif ($professional?->is_active) {
            $professional->update(['is_active' => false]);
        }

        $user = $person->user;

        if ($user !== null && ! $user->canManageTenant() && array_key_exists('role', $data)) {
            $role = AgendaRole::tryFrom((string) $data['role']) ?? AgendaRole::None;
            app(AccessManager::class)->set($user, AgendaModule::KEY, $role === AgendaRole::None ? null : $role->value);
        }
    }

    public function remove(Person $person): void
    {
        Professional::query()->where('person_id', $person->getKey())->update(['is_active' => false]);

        if ($person->user !== null) {
            app(AccessManager::class)->set($person->user, AgendaModule::KEY, null);
        }
    }

    public function roleLabel(Person $person): ?string
    {
        $role = $person->user ? $this->access->role($person->user) : AgendaRole::None;

        if ($role !== AgendaRole::None) {
            return $role->getLabel();
        }

        return Professional::query()->active()->where('person_id', $person->getKey())->exists() ? AgendaRole::Professional->getLabel() : null;
    }
}
