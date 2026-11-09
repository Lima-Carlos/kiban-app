<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Afd\AfdLine;
use App\Modules\TimeClock\Models\AfdRecord;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\People\Person;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Brazil: what the AFD records besides the punches, in the same numbering (NSR) and at the moment it happens:
 * - type 2: the employer's identification included or changed (Configurações do ponto);
 * - type 5: an employee included (starts clocking in), changed (name or CPF) or excluded (stopped).
 * Only for the "Brasil (CLT)" profile. Listens to the models (TimeClockModule::boot), so every path is covered.
 */
class AfdLog
{
    public function __construct(protected TenantContext $context) {}

    public function employerSaved(TimeClockSetting $settings): void
    {
        if (! $settings->isBrazil() || blank($settings->employer_document) || blank($settings->employer_name)) {
            return;
        }

        $line = fn (int $nsr, CarbonImmutable $at): string => AfdLine::employer(
            $nsr, $at, $this->responsibleCpf(), (string) $settings->employer_document_type, (string) $settings->employer_document,
            (string) $settings->employer_name, $this->context->get()->name,
        );

        // Only when the identification differs from the last one recorded (type, document and name: positions 49 to 227).
        $last = AfdRecord::query()->where('type', AfdRecord::EMPLOYER)->orderByDesc('nsr')->value('line');

        if ($last !== null && mb_substr($last, 48, 179) === mb_substr($line(0, CarbonImmutable::now()), 48, 179)) {
            return;
        }

        $this->append(AfdRecord::EMPLOYER, null, $line);
    }

    public function employeeSaved(Employee $employee): void
    {
        $operation = match (true) {
            $employee->wasRecentlyCreated && $employee->is_active => 'I',
            $employee->wasChanged('is_active') => $employee->is_active ? 'I' : 'E',
            default => null,
        };

        if ($operation !== null) {
            $this->employee($employee, $operation);
        }
    }

    /** Name or CPF changed of someone who clocks in. */
    public function personSaved(Person $person): void
    {
        if (! $person->wasChanged(['name', 'document'])) {
            return;
        }

        $employee = Employee::query()->active()->where('person_id', $person->getKey())->first();

        if ($employee !== null) {
            $this->employee($employee->setRelation('person', $person), 'A');
        }
    }

    protected function employee(Employee $employee, string $operation): void
    {
        if (! TimeClockSetting::current()->isBrazil()) {
            return;
        }

        $person = $employee->person;

        $this->append(AfdRecord::EMPLOYEE, $employee->getKey(), fn (int $nsr, CarbonImmutable $at): string => AfdLine::employee(
            $nsr, $at, $operation, $person?->document, (string) $person?->name, $this->responsibleCpf(),
        ));
    }

    /** Takes the next number of the company (the same lock as the punches) and keeps the line. */
    protected function append(int $type, ?int $employeeId, callable $line): void
    {
        DB::transaction(function () use ($type, $employeeId, $line): void {
            $settings = TimeClockSetting::query()->lockForUpdate()->first() ?? TimeClockSetting::current();
            $nsr = (int) $settings->last_nsr + 1;
            $now = CarbonImmutable::now();

            $record = new AfdRecord;
            $record->forceFill([
                'nsr' => $nsr,
                'type' => $type,
                'recorded_at' => $now,
                'line' => $line($nsr, $now->setTimezone(FilamentTimezone::get())),
                'employee_id' => $employeeId,
            ])->save();

            // saveQuietly: the employer record listens to the settings being saved.
            $settings->forceFill(['last_nsr' => $nsr])->saveQuietly();
        });
    }

    /** The CPF of whoever made the change, when known (the person linked to the logged-in user). */
    protected function responsibleCpf(): ?string
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        return Person::query()->where('user_id', $user->getKey())->value('document');
    }
}
