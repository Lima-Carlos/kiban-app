<?php

namespace App\Modules\Fleet\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentType: string implements HasLabel
{
    case Registration = 'registration';

    case Insurance = 'insurance';

    case Tax = 'tax';

    case Inspection = 'inspection';

    case Other = 'other';

    public function getLabel(): string
    {
        return __('fleet.enums.document_type.'.$this->value);
    }
}
