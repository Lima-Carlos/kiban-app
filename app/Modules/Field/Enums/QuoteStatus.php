<?php

namespace App\Modules\Field\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A quote: being written, sent to the customer, approved (becomes a work order) or declined.
 * "Expired" is not stored: it is a sent quote past its date.
 */
enum QuoteStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';

    case Sent = 'sent';

    case Approved = 'approved';

    case Declined = 'declined';

    public function getLabel(): string
    {
        return __('field.enums.quote_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'info',
            self::Approved => 'success',
            self::Declined => 'danger',
        };
    }

    public function isAnswered(): bool
    {
        return in_array($this, [self::Approved, self::Declined], true);
    }
}
