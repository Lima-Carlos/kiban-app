<?php

namespace App\Modules\Agenda\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Role of a person in the Kiban Core Agenda. Owners and administrators of the company are always managers.
 */
enum AgendaRole: string implements HasLabel
{
    /** Sees and changes everything (services, professionals, settings, commissions). */
    case Manager = 'manager';

    /** Front desk: clients, pets, appointments, reminders and packages. */
    case Desk = 'desk';

    /** Sees their own agenda and moves their appointments (arrived, in service, ready). */
    case Professional = 'professional';

    /** Only looks. */
    case Viewer = 'viewer';

    /** No access (default). */
    case None = 'none';

    public function getLabel(): string
    {
        return __('agenda.enums.role.'.$this->value);
    }

    /**
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return [self::Desk, self::Professional, self::Manager, self::Viewer];
    }

    /** Books, moves and cancels any appointment; registers clients. */
    public function runsTheDesk(): bool
    {
        return in_array($this, [self::Manager, self::Desk], true);
    }
}
