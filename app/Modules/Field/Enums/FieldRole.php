<?php

namespace App\Modules\Field\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Role of a person in Kiban Campo. Owners and administrators of the company are always managers.
 */
enum FieldRole: string implements HasLabel
{
    /** Sees and changes everything (customers, settings, work orders, technicians). */
    case Manager = 'manager';

    /** Service desk: registers customers and opens and schedules the work orders. */
    case Dispatcher = 'dispatcher';

    /** Does the work orders on the phone (from part 4); counts in the plan limit of technicians. */
    case Technician = 'technician';

    /** Only looks. */
    case Viewer = 'viewer';

    /** No access (default). */
    case None = 'none';

    public function getLabel(): string
    {
        return __('field.enums.role.'.$this->value);
    }

    /**
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return [self::Technician, self::Dispatcher, self::Manager, self::Viewer];
    }

    /** Registers and changes customers. */
    public function managesCustomers(): bool
    {
        return in_array($this, [self::Manager, self::Dispatcher], true);
    }
}
