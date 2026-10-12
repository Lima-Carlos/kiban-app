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

    /**
     * What can be put in the tank of a vehicle with this fuel type.
     *
     * @return array<int, self>
     */
    public function acceptedFuels(): array
    {
        return match ($this) {
            self::Gasoline => [self::Gasoline],
            self::Ethanol => [self::Ethanol],
            self::Flex, self::Hybrid => [self::Gasoline, self::Ethanol],
            self::Diesel => [self::Diesel],
            self::Cng => [self::Cng, self::Gasoline, self::Ethanol],
            self::Electric => [],
        };
    }

    /**
     * Fuels sold at the pump (what is chosen when finishing a fuel-up).
     *
     * @return array<int, self>
     */
    public static function pumpFuels(): array
    {
        return [self::Gasoline, self::Ethanol, self::Diesel, self::Cng];
    }
}
