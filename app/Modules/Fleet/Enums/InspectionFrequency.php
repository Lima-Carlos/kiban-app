<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How often the company wants each vehicle inspected (Frota settings).
 */
enum InspectionFrequency: string implements HasLabel
{
    case Daily = 'daily';

    case Weekly = 'weekly';

    case Monthly = 'monthly';

    /** No reminders: inspections only when someone wants. */
    case Free = 'free';

    public function getLabel(): string
    {
        return __('fleet.enums.inspection_frequency.'.$this->value);
    }
}
