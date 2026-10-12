<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RequestStatus: string implements HasColor, HasLabel
{
    case Open = 'open';

    case Scheduled = 'scheduled';

    case Done = 'done';

    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return __('fleet.enums.request_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Scheduled => 'info',
            self::Done => 'success',
            self::Rejected => 'gray',
        };
    }
}
