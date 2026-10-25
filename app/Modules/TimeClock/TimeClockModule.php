<?php

namespace App\Modules\TimeClock;

use App\Filament\Pages\PeoplePage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Module;
use App\Modules\Step;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Filament\Adjustments\AdjustmentResource;
use App\Modules\TimeClock\Filament\ClosingsPage;
use App\Modules\TimeClock\Filament\Employees\EmployeeResource;
use App\Modules\TimeClock\Filament\Holidays\HolidayResource;
use App\Modules\TimeClock\Filament\Holidays\NationalHolidaysWidget;
use App\Modules\TimeClock\Filament\Kiosks\KioskResource;
use App\Modules\TimeClock\Filament\Punches\PunchResource;
use App\Modules\TimeClock\Filament\PunchPage;
use App\Modules\TimeClock\Filament\Schedules\ScheduleResource;
use App\Modules\TimeClock\Filament\TimeClockSettingsPage;
use App\Modules\TimeClock\Filament\TimesheetPage;
use App\Modules\TimeClock\Filament\Workplaces\WorkplaceResource;
use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Holiday;
use App\Modules\TimeClock\Models\Kiosk;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Policies\AdjustmentPolicy;
use App\Modules\TimeClock\Policies\EmployeePolicy;
use App\Modules\TimeClock\Policies\HolidayPolicy;
use App\Modules\TimeClock\Policies\KioskPolicy;
use App\Modules\TimeClock\Policies\PunchPolicy;
use App\Modules\TimeClock\Policies\SchedulePolicy;
use App\Modules\TimeClock\Policies\WorkplacePolicy;
use App\Modules\TimeClock\Services\EmployeeService;
use App\Modules\TimeClock\Services\ScheduleCalendar;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\Services\TimeClockNotices;
use App\Modules\TimeClock\Services\TimeClockPeople;
use App\People\Person;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;

/**
 * Kiban Ponto (time clock): employees, workplaces and, in the next parts, schedules, punches on the phone
 * or on the company tablet, hour bank and the monthly time sheet. Rules per country: "Brazil (CLT)"
 * (official rules) or "Custom". Product code in the Core: "ponto"; plan limit: active employees.
 */
class TimeClockModule extends Module
{
    public const KEY = 'ponto';

    /** Plan limit in the Core: number of active employees. */
    public const LIMIT_EMPLOYEES = 'employees';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('timeclock.module');
    }

    public function icon(): string
    {
        return 'heroicon-o-clock';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'rules',
                labelKey: 'timeclock.steps.rules',
                descriptionKey: 'timeclock.steps.rules_help',
                done: fn (Tenant $tenant): bool => TimeClockSetting::query()->whereNotNull('configured_at')->exists(),
                url: fn (): string => TimeClockSettingsPage::getUrl(),
            ),
            new Step(
                key: 'workplace',
                labelKey: 'timeclock.steps.workplace',
                descriptionKey: 'timeclock.steps.workplace_help',
                requires: ['rules'],
                done: fn (Tenant $tenant): bool => Workplace::query()->exists(),
                url: fn (): string => WorkplaceResource::getUrl('index'),
            ),
            new Step(
                key: 'schedule',
                labelKey: 'timeclock.steps.schedule',
                descriptionKey: 'timeclock.steps.schedule_help',
                requires: ['rules'],
                done: fn (Tenant $tenant): bool => Schedule::query()->exists(),
                url: fn (): string => ScheduleResource::getUrl('index'),
            ),
            new Step(
                key: 'employees',
                labelKey: 'timeclock.steps.employees',
                descriptionKey: 'timeclock.steps.employees_help',
                requires: ['rules', 'schedule'],
                done: fn (Tenant $tenant): bool => Employee::query()->active()->exists(),
                url: fn (): string => PeoplePage::getUrl(['add' => 1]),
            ),
        ];
    }

    public function resources(): array
    {
        return [PunchResource::class, AdjustmentResource::class, EmployeeResource::class, ScheduleResource::class, WorkplaceResource::class, HolidayResource::class, KioskResource::class];
    }

    public function widgets(): array
    {
        return [NationalHolidaysWidget::class];
    }

    public function pages(): array
    {
        return [PunchPage::class, TimesheetPage::class, ClosingsPage::class, TimeClockSettingsPage::class];
    }

    public function showsSetupTo(User $user): bool
    {
        return app(TimeClockAccess::class)->isManager($user);
    }

    /** Someone who clocks in: the big "Clock in" button and today's punches on the start page. */
    public function personalHome(User $user): ?array
    {
        $employee = app(TimeClockAccess::class)->employeeFor($user);

        if ($employee === null || ! PunchPage::canAccess()) {
            return null;
        }

        $today = CarbonImmutable::now($employee->workplace?->timezone ?? FilamentTimezone::get());

        return [
            'view' => 'timeclock.home.employee',
            'data' => [
                'today' => $today,
                'day' => app(ScheduleCalendar::class)->period($employee, $today, $today)[$today->toDateString()],
                'punches' => Punch::query()->where('employee_id', $employee->getKey())
                    ->whereBetween('punched_at', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])
                    ->orderBy('punched_at')->get(),
                'url' => PunchPage::getUrl(),
            ],
        ];
    }

    public function attention(User $user): array
    {
        return app(TimeClockNotices::class)->attention($user);
    }

    public function roles(): array
    {
        return collect(TimeClockRole::assignable())->mapWithKeys(fn (TimeClockRole $role): array => [$role->value => $role->getLabel()])->all();
    }

    /** Someone with a login who clocks in is an employee without anything else. */
    public function implicitRole(User $user): ?string
    {
        return app(TimeClockAccess::class)->employeeFor($user) !== null ? TimeClockRole::Employee->value : null;
    }

    public function managesPeople(User $user): bool
    {
        return app(TimeClockAccess::class)->isManager($user);
    }

    /** HR sees the CPF and the other documents (owner's decision). */
    public function seesPersonDocuments(User $user): bool
    {
        return app(TimeClockAccess::class)->isManager($user);
    }

    public function personFields(): array
    {
        return app(TimeClockPeople::class)->fields();
    }

    public function personData(Person $person): array
    {
        return app(TimeClockPeople::class)->data($person);
    }

    public function savePerson(Person $person, array $data): void
    {
        app(TimeClockPeople::class)->save($person, $data);
    }

    public function checkPerson(?Person $person, array $data, array $details): void
    {
        if ($data['clocks_in'] ?? false) {
            app(EmployeeService::class)->check($person, $data, $details['document'] ?? null);
        }
    }

    public function removePerson(Person $person): void
    {
        app(TimeClockPeople::class)->remove($person);
    }

    public function personRoleLabel(Person $person): ?string
    {
        return app(TimeClockPeople::class)->roleLabel($person);
    }

    public function policies(): array
    {
        return [
            Workplace::class => WorkplacePolicy::class,
            Employee::class => EmployeePolicy::class,
            Schedule::class => SchedulePolicy::class,
            Holiday::class => HolidayPolicy::class,
            Punch::class => PunchPolicy::class,
            Adjustment::class => AdjustmentPolicy::class,
            Kiosk::class => KioskPolicy::class,
        ];
    }
}
