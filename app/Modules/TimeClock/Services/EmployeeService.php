<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\TimeClockModule;
use App\People\Person;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;

/**
 * Employees of the time clock (who clocks in), always tied to a person of the People screen.
 *
 * - active employees count in the plan limit ("employees");
 * - Brazil (CLT): the CPF is required (official files); the PIS is optional;
 * - "external" employees (work in the street) need no workplace;
 * - someone who stops clocking in keeps the record (history), inactive.
 */
class EmployeeService
{
    public function __construct(protected TenantContext $context) {}

    public function limitReached(): bool
    {
        $max = $this->context->get()->license()->limitWithTolerance(TimeClockModule::KEY, TimeClockModule::LIMIT_EMPLOYEES);

        return $max !== null && Employee::query()->active()->count() >= $max;
    }

    /**
     * The rules, checked before anything is saved (and before a login is created in the Core).
     *
     * @param  array<string, mixed>  $data
     */
    public function check(?Person $person, array $data, ?string $document): void
    {
        $employee = $person ? Employee::query()->where('person_id', $person->getKey())->first() : null;

        if (($employee === null || ! $employee->is_active) && $this->limitReached()) {
            throw new PersonRuleException(__('app.denied.limit', ['limit' => $this->context->get()->license()->limit(TimeClockModule::KEY, TimeClockModule::LIMIT_EMPLOYEES)]));
        }

        if (TimeClockSetting::current()->isBrazil() && blank($document)) {
            throw new PersonRuleException(__('timeclock.errors.cpf_required', ['name' => $person?->name ?? __('timeclock.employees.singular')]));
        }

        if (blank($data['admitted_on'] ?? null)) {
            throw new PersonRuleException(__('timeclock.errors.admission_required'));
        }

        if (blank($data['schedule_id'] ?? null) || ! Schedule::query()->whereKey($data['schedule_id'])->exists()) {
            throw new PersonRuleException(__('timeclock.errors.schedule_required'));
        }

        if (filled($data['schedule_from'] ?? null) && (string) $data['schedule_from'] < (string) $data['admitted_on']) {
            throw new PersonRuleException(__('timeclock.errors.schedule_before_admission'));
        }

        if (! ($data['is_external'] ?? false) && (blank($data['workplace_id'] ?? null) || ! Workplace::query()->whereKey($data['workplace_id'])->exists())) {
            throw new PersonRuleException(__('timeclock.errors.workplace_required'));
        }
    }

    /**
     * @param  array{registration?: ?string, pis?: ?string, admitted_on?: mixed, job_title?: ?string, workplace_id?: ?int, is_external?: bool}  $data
     */
    public function save(Person $person, array $data): Employee
    {
        $this->check($person, $data, $person->document_type === Person::CPF ? $person->document : null);

        $employee = Employee::query()->where('person_id', $person->getKey())->first() ?? new Employee;
        $external = (bool) ($data['is_external'] ?? false);
        $workplace = $external ? null : Workplace::query()->whereKey($data['workplace_id'])->first();

        $employee->fill([
            'registration' => $data['registration'] ?? null,
            'pis' => TimeClockSetting::current()->isBrazil() ? (preg_replace('/\D/', '', (string) ($data['pis'] ?? '')) ?: null) : null,
            'admitted_on' => $data['admitted_on'],
            'job_title' => $data['job_title'] ?? null,
            'workplace_id' => $workplace?->getKey(),
            'is_external' => $external,
            'is_active' => true,
            'terminated_on' => null,
        ]);
        $employee->forceFill(['person_id' => $person->getKey()])->save();

        return $employee;
    }

    /** Stops clocking in (or left the company): the record and its history stay. */
    public function deactivate(Person $person): void
    {
        $employee = Employee::query()->active()->where('person_id', $person->getKey())->first();
        $employee?->forceFill(['is_active' => false, 'terminated_on' => $employee->terminated_on ?? now()->toDateString()])->save();
    }
}
