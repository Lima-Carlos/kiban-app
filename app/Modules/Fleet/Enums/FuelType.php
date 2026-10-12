<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

enum FuelType: string implements HasLabel
{
    case Gasoline = 'gasoline';

    case Ethanol = 'ethanol';

    case Flex = 'flex';

    case Diesel = 'diesel';

    case Cng = 'cng';

    case Electric = 'electric';

    case Hybrid = 'hybrid';

    public function getLabel(): string
    {
        return __('fleet.enums.fuel_type.'.$this->value);
    }
}
