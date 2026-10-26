<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Closing;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The month of an employee: the frozen copy when the month is closed, otherwise the calculation of now
 * (marked as a preview). Used by the time sheet screen and by the time sheet PDF ("espelho de ponto").
 */
class TimesheetDocument
{
    public function __construct(
        protected TenantContext $context,
        protected DayCalculator $calculator,
        protected HourBank $bank,
        protected MonthClosing $closing,
    ) {}

    /**
     * @return array{days: array<string, array<string, mixed>>, totals: array<string, int>, bank: ?array, closing: ?Closing}
     */
    public function month(Employee $employee, CarbonInterface $month): array
    {
        $start = CarbonImmutable::parse($month->format('Y-m-01'));
        $closing = $this->closing->current($employee, $start);

        if ($closing !== null) {
            $closing->setRelation('employee', $employee);

            return ['days' => $this->closing->days($closing), 'totals' => (array) $closing->totals, 'bank' => $closing->bank, 'closing' => $closing];
        }

        $days = $this->calculator->period($employee, $start, $start->endOfMonth());
        $bank = null;

        if ($this->bank->enabled()) {
            $end = $start->endOfMonth();
            $bank = $this->bank->balance($employee, $end->isFuture() ? CarbonImmutable::today() : $end);
        }

        return ['days' => $days, 'totals' => $this->calculator->totals($days), 'bank' => $bank, 'closing' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Employee $employee, CarbonInterface $month): array
    {
        $employee->loadMissing(['person.team', 'workplace']);
        $start = CarbonImmutable::parse($month->format('Y-m-01'));
        $settings = TimeClockSetting::current();

        return $this->month($employee, $start) + [
            'employee' => $employee,
            'settings' => $settings,
            'company' => $settings->employer_name ?: $this->context->get()->name,
            'employerDocument' => $this->employerDocument($settings),
            'start' => $start,
            'monthLabel' => $this->closing->monthLabel($start),
            'generatedAt' => CarbonImmutable::now($this->calculator->timezone($employee)),
        ];
    }

    public function pdf(Employee $employee, CarbonInterface $month): string
    {
        return Pdf::setOption('isFontSubsettingEnabled', true)->loadView('timeclock.pdf.timesheet', $this->data($employee, $month))->setPaper('a4', 'landscape')->output();
    }

    public function fileName(Employee $employee, CarbonInterface $month): string
    {
        return Str::slug(__('timeclock.closing.file', ['name' => $employee->person?->name, 'month' => $month->format('Y-m')])).'.pdf';
    }

    /** "CNPJ 12.345.678/0001-90" (Brazil), if filled. */
    public function employerDocument(TimeClockSetting $settings): ?string
    {
        $number = (string) $settings->employer_document;

        if ($number === '') {
            return null;
        }

        return match ($settings->employer_document_type) {
            'cnpj' => 'CNPJ '.substr($number, 0, 2).'.'.substr($number, 2, 3).'.'.substr($number, 5, 3).'/'.substr($number, 8, 4).'-'.substr($number, 12),
            'cpf' => 'CPF '.substr($number, 0, 3).'.'.substr($number, 3, 3).'.'.substr($number, 6, 3).'-'.substr($number, 9),
            default => $number,
        };
    }
}
