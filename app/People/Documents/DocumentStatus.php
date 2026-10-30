<?php

namespace App\People\Documents;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Situation of a person's document: fine, expiring (within the type's warning days), expired or no expiry.
 */
enum DocumentStatus: string implements HasColor, HasLabel
{
    case Ok = 'ok';

    case Soon = 'soon';

    case Overdue = 'overdue';

    case NoExpiry = 'no_expiry';

    public function getLabel(): string
    {
        return __('documents.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Soon => 'warning',
            self::Overdue => 'danger',
            self::NoExpiry => 'gray',
        };
    }
}
