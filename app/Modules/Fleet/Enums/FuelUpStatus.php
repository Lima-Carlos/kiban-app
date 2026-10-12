<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FuelUpStatus: string implements HasColor, HasLabel
{
    /** Code generated, waiting for the fuel-up to be finished. */
    case Authorized = 'authorized';

    case Completed = 'completed';

    /** Not finished in time. */
    case Expired = 'expired';

    case Canceled = 'canceled';

    public function getLabel(): string
    {
        return __('fleet.enums.fuel_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Authorized => 'warning',
            self::Completed => 'success',
            self::Expired => 'danger',
            self::Canceled => 'gray',
        };
    }
}
