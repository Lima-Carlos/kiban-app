<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Employee;
use Carbon\CarbonInterface;

/**
 * The closing of the month (part 5): once closed, the days of that month do not change any more.
 */
class MonthClosing
{
    public function isClosed(Employee $employee, CarbonInterface $date): bool
    {
        return false;
    }
}
