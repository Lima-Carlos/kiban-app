<?php

namespace App\Modules\Field\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Where a work order is: open (no time yet), scheduled, in progress / paused (the technician, part 4), done or
 * canceled. Done and canceled orders do not change any more.
 */
enum OrderStatus: string implements HasColor, HasIcon, HasLabel
{
    case Open = 'open';

    case Scheduled = 'scheduled';

    case InProgress = 'in_progress';

    case Paused = 'paused';

    case Done = 'done';

    case Canceled = 'canceled';

    public function getLabel(): string
    {
        return __('field.enums.order_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'gray',
            self::Scheduled => 'info',
            self::InProgress => 'warning',
            self::Paused => 'danger',
            self::Done => 'success',
            self::Canceled => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Open => Heroicon::OutlinedInboxArrowDown,
            self::Scheduled => Heroicon::OutlinedCalendarDays,
            self::InProgress => Heroicon::OutlinedWrenchScrewdriver,
            self::Paused => Heroicon::OutlinedPauseCircle,
            self::Done => Heroicon::OutlinedCheckCircle,
            self::Canceled => Heroicon::OutlinedXCircle,
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Done, self::Canceled], true);
    }

    /**
     * Statuses still to be done (on the agenda and in the counts).
     *
     * @return array<int, self>
     */
    public static function pending(): array
    {
        return [self::Open, self::Scheduled, self::InProgress, self::Paused];
    }
}
