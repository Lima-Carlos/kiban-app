<?php

namespace App\Modules\Field\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderPriority: string implements HasColor, HasLabel
{
    case Normal = 'normal';

    case High = 'high';

    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return __('field.enums.priority.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Normal => 'gray',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }
}
