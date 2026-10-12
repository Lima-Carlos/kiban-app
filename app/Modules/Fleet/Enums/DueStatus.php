<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Situation of an oil change, revision or document: fine, coming soon (within the alert window) or overdue.
 */
enum DueStatus: string implements HasColor, HasLabel
{
    case NotSet = 'not_set';

    case Ok = 'ok';

    case Soon = 'soon';

    case Overdue = 'overdue';

    public function getLabel(): string
    {
        return __('fleet.enums.due_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotSet => 'gray',
            self::Ok => 'success',
            self::Soon => 'warning',
            self::Overdue => 'danger',
        };
    }
}
