<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AppointmentStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'scheduled';

    case Done = 'done';

    case Canceled = 'canceled';

    public function getLabel(): string
    {
        return __('fleet.enums.appointment_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'warning',
            self::Done => 'success',
            self::Canceled => 'gray',
        };
    }
}
