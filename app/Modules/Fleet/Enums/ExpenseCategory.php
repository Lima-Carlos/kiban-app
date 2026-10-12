<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExpenseCategory: string implements HasLabel
{
    case Tax = 'tax';

    case Licensing = 'licensing';

    case Insurance = 'insurance';

    case Fine = 'fine';

    case Toll = 'toll';

    case Parking = 'parking';

    case Tires = 'tires';

    case Accessories = 'accessories';

    case Other = 'other';

    public function getLabel(): string
    {
        return __('fleet.enums.expense_category.'.$this->value);
    }
}
