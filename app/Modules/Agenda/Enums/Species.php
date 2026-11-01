<?php

namespace App\Modules\Agenda\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Kind of pet.
 */
enum Species: string implements HasLabel
{
    case Dog = 'dog';
    case Cat = 'cat';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('agenda.enums.species.'.$this->value);
    }
}
