<?php

namespace App\Modules\TimeClock\Services;

use App\Access\AccessManager;
use App\Models\ModuleAccess;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PeopleForm;
use App\People\Person;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The Ponto part of the "People" screen: whether the person clocks in (employee data) and, for people
 * with a login, their access to the time clock (HR manager, viewer...). Works without a login too.
 */
class TimeClockPeople
{
    public function __construct(
        protected TimeClockAccess $access,
        protected EmployeeService $employees,
    ) {}

    /**
     * @return array<int, mixed>
     */
    public function fields(): array
    {
        $clocksIn = fn (Get $get): bool => (bool) $get('clocks_in');
        $brazil = TimeClockSetting::current()->isBrazil();

        return [
            Toggle::make('clocks_in')
                ->label(__('timeclock.people.clocks_in'))
                ->helperText($brazil ? __('timeclock.people.clocks_in_help_br') : __('timeclock.people.clocks_in_help'))
                ->live(),
            Grid::make(['default' => 1, 'sm' => 2])
                ->visible($clocksIn)
                ->components([
                    DatePicker::make('admitted_on')
                        ->label(__('timeclock.fields.admitted_on'))
                        ->required($clocksIn),
                    TextInput::make('job_title')
                        ->label(__('timeclock.fields.job_title'))
                        ->maxLength(120),
                    TextInput::make('registration')
                        ->label(__('timeclock.fields.registration'))
                        ->maxLength(30),
                    TextInput::make('pis')
                        ->label(__('timeclock.fields.pis'))
                        ->helperText(__('timeclock.helpers.pis'))
                        ->visible($brazil)
                        ->maxLength(14),
                    Toggle::make('is_external')
                        ->label(__('timeclock.fields.is_external'))
                        ->helperText(__('timeclock.helpers.is_external'))
                        ->live()
                        ->columnSpanFull(),
                    Select::make('schedule_id')
                        ->label(__('timeclock.fields.schedule'))
                        ->options(fn (): array => Schedule::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->required($clocksIn)
                        ->helperText(fn (): ?string => Schedule::query()->exists() ? null : __('timeclock.helpers.no_schedule')),
                    DatePicker::make('schedule_from')
                        ->label(__('timeclock.fields.schedule_from'))
                        ->helperText(__('timeclock.helpers.schedule_from')),
                    Select::make('workplace_id')
                        ->label(__('timeclock.fields.workplace'))
                        ->options(fn (): array => Workplace::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => ! $get('is_external'))
                        ->required(fn (Get $get): bool => (bool) $get('clocks_in') && ! $get('is_external'))
                        ->helperText(fn (): ?string => Workplace::query()->exists() ? null : __('timeclock.helpers.no_workplace'))
                        ->columnSpanFull(),
                ]),
            Select::make('role')
                ->label(__('timeclock.people.role'))
                ->helperText(__('timeclock.people.role_help'))
                ->options(collect([TimeClockRole::None, ...TimeClockRole::assignable()])->mapWithKeys(fn (TimeClockRole $role): array => [$role->value => $role->getLabel()])->all())
                ->default(TimeClockRole::None->value)
                ->visible(PeopleForm::hasLogin()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Person $person): array
    {
        $employee = Employee::query()->where('person_id', $person->getKey())->first();
        $current = $employee ? app(ScheduleService::class)->current($employee) : null;
        // The role given on purpose (an employee without a role still clocks in: that comes from the record).
        $role = $person->user ? ModuleAccess::query()->where('user_id', $person->user_id)->where('module', TimeClockModule::KEY)->value('role') : null;

        return [
            'clocks_in' => (bool) $employee?->is_active,
            'admitted_on' => $employee?->admitted_on?->toDateString(),
            'job_title' => $employee?->job_title,
            'registration' => $employee?->registration,
            'pis' => $employee?->pis,
            'is_external' => (bool) $employee?->is_external,
            'workplace_id' => $employee?->workplace_id,
            'schedule_id' => $current?->schedule_id,
            'schedule_from' => $current?->starts_on?->toDateString(),
            'role' => $role ?? TimeClockRole::None->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Person $person, array $data): void
    {
        if ($data['clocks_in'] ?? false) {
            $isNew = ! Employee::query()->where('person_id', $person->getKey())->exists();
            $employee = $this->employees->save($person, $data);

            // The schedule counts from the chosen day; empty: from the hiring date (new) or from today (a change).
            $from = filled($data['schedule_from'] ?? null) ? CarbonImmutable::parse($data['schedule_from']) : ($isNew ? CarbonImmutable::parse($employee->admitted_on->toDateString()) : CarbonImmutable::today());
            app(ScheduleService::class)->assign($employee, Schedule::query()->findOrFail($data['schedule_id']), $from);
        } else {
            $this->employees->deactivate($person);
        }

        $user = $person->user;

        // Owners and administrators are always managers of the time clock.
        if ($user !== null && ! $user->canManageTenant()) {
            $role = TimeClockRole::tryFrom((string) ($data['role'] ?? '')) ?? TimeClockRole::None;
            app(AccessManager::class)->set($user, TimeClockModule::KEY, $role === TimeClockRole::None ? null : $role->value);
        }
    }

    public function remove(Person $person): void
    {
        $this->employees->deactivate($person);

        if ($person->user !== null) {
            app(AccessManager::class)->set($person->user, TimeClockModule::KEY, null);
        }
    }

    public function roleLabel(Person $person): ?string
    {
        $role = $person->user ? $this->access->role($person->user) : TimeClockRole::None;

        if ($role !== TimeClockRole::None) {
            return $role->getLabel();
        }

        return Employee::query()->active()->where('person_id', $person->getKey())->exists() ? TimeClockRole::Employee->getLabel() : null;
    }
}
