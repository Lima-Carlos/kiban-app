<?php

namespace App\Modules\TimeClock\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Role of a person in the time clock. Owners and administrators of the company are always managers.
 */
enum TimeClockRole: string implements HasLabel
{
    /** HR: sees and changes everything, sees the documents (CPF). */
    case Manager = 'manager';

    /** Clocks in and sees only their own time sheet. */
    case Employee = 'employee';

    /** Only looks. */
    case Viewer = 'viewer';

    /** No access (default). */
    case None = 'none';

    public function getLabel(): string
    {
        return __('timeclock.enums.role.'.$this->value);
    }

    /**
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return [self::Employee, self::Manager, self::Viewer];
    }
}
