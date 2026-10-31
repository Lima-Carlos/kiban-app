<?php

namespace App\People\Documents;

use Filament\Support\Contracts\HasLabel;

/**
 * A course taken for the first time (initial) or again before / after it expired (refresher).
 */
enum TrainingKind: string implements HasLabel
{
    case Initial = 'initial';

    case Refresher = 'refresher';

    public function getLabel(): string
    {
        return __('documents.trainings.kind.'.$this->value);
    }
}
