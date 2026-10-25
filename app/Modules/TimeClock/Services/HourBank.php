<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\TimeClockSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The hour bank (when the company uses it): the extra minutes of normal days become credit and the minutes
 * short (late arrival, early leave) are taken from it, oldest credit first. A credit not used within the
 * company's deadline (in Brazil 6 or 12 months) expires and must be paid as overtime.
 *
 * Kept simple and visible on purpose: whole-day absences are not taken from the bank (they are reported apart),
 * and overtime on days off and holidays is paid, not banked.
 */
class HourBank
{
    public function __construct(protected DayCalculator $days) {}

    public function enabled(): bool
    {
        return (bool) TimeClockSetting::current()->hour_bank;
    }

    /**
     * The bank on a day: balance (negative = the person owes), credits and debits of the period, and the
     * minutes that expired (to be paid).
     *
     * @return array{balance: int, credits: int, debits: int, expired: int}
     */
    public function balance(Employee $employee, CarbonInterface $until): array
    {
        $result = ['balance' => 0, 'credits' => 0, 'debits' => 0, 'expired' => 0];

        if (! $this->enabled() || $employee->admitted_on === null || $employee->admitted_on->gt($until)) {
            return $result;
        }

        $months = (int) TimeClockSetting::current()->hour_bank_months;
        $open = [];
        $debt = 0;

        foreach ($this->days->period($employee, $employee->admitted_on, $until) as $day) {
            if (! $day['counts'] || $day['type'] !== 'work') {
                continue;
            }

            $date = $day['date'];
            $open = $this->expire($open, $date, $months, $result);
            $credit = $day['extra'];
            $debit = $day['absence'] ? 0 : $day['deficit'];
            $result['credits'] += $credit;
            $result['debits'] += $debit;

            if ($credit > 0) {
                $paid = min($debt, $credit);
                $debt -= $paid;
                $credit -= $paid;

                if ($credit > 0) {
                    $open[] = ['date' => $date, 'minutes' => $credit];
                }
            }

            while ($debit > 0 && $open !== []) {
                $used = min($debit, $open[0]['minutes']);
                $open[0]['minutes'] -= $used;
                $debit -= $used;

                if ($open[0]['minutes'] === 0) {
                    array_shift($open);
                }
            }

            $debt += $debit;
        }

        $open = $this->expire($open, CarbonImmutable::parse($until->toDateString()), $months, $result);
        $result['balance'] = (int) array_sum(array_column($open, 'minutes')) - $debt;

        return $result;
    }

    /**
     * @param  array<int, array{date: CarbonImmutable, minutes: int}>  $open
     * @param  array{balance: int, credits: int, debits: int, expired: int}  $result
     * @return array<int, array{date: CarbonImmutable, minutes: int}>
     */
    protected function expire(array $open, CarbonImmutable $on, int $months, array &$result): array
    {
        while ($open !== [] && $open[0]['date']->addMonthsNoOverflow($months)->lt($on)) {
            $result['expired'] += $open[0]['minutes'];
            array_shift($open);
        }

        return $open;
    }
}
