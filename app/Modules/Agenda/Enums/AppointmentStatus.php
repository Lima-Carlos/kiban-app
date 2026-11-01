<?php

namespace App\Modules\Agenda\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The way of an appointment: scheduled → confirmed → arrived → in service → ready ("pet ready") → delivered.
 * Off the way: no-show and canceled. Only forward moves; delivered, no-show and canceled are final.
 */
enum AppointmentStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case InService = 'in_service';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case NoShow = 'no_show';
    case Canceled = 'canceled';

    /** The normal way, in order. */
    public const FLOW = ['scheduled', 'confirmed', 'arrived', 'in_service', 'ready', 'delivered'];

    public function getLabel(): string
    {
        return __('agenda.enums.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'gray',
            self::Confirmed => 'info',
            self::Arrived => 'warning',
            self::InService => 'primary',
            self::Ready => 'success',
            self::Delivered => 'success',
            self::NoShow => 'danger',
            self::Canceled => 'gray',
        };
    }

    /** Still to happen (holds the professional's time). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::Confirmed], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::NoShow, self::Canceled], true);
    }

    /** Takes the professional's time on the agenda (not canceled and not a no-show). */
    public function holdsTime(): bool
    {
        return ! in_array($this, [self::NoShow, self::Canceled], true);
    }

    /** The service was done (counts for commissions and packages). */
    public function isDone(): bool
    {
        return in_array($this, [self::Ready, self::Delivered], true);
    }

    public function position(): int
    {
        $position = array_search($this->value, self::FLOW, true);

        return $position === false ? -1 : $position;
    }

    /** A forward move on the normal way (any step ahead: the desk may skip "confirmed" or "arrived"). */
    public function canMoveTo(self $next): bool
    {
        if ($this->isFinal()) {
            return false;
        }

        return match ($next) {
            self::NoShow => $this->isOpen(),
            self::Canceled => in_array($this, [self::Scheduled, self::Confirmed, self::Arrived], true),
            default => $next->position() > $this->position(),
        };
    }

    /**
     * Statuses that hold time on the agenda.
     *
     * @return array<int, string>
     */
    public static function holding(): array
    {
        return array_map(fn (self $status): string => $status->value, array_filter(self::cases(), fn (self $status): bool => $status->holdsTime()));
    }
}
