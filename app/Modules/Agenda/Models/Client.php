<?php

namespace App\Modules\Agenda\Models;

use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Field\Models\Customer;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client of the Agenda (in a pet shop: the pet's tutor). It is the same record as the customers of the Campo
 * (table fd_customers): one company, one list of customers, never two copies to keep in step. This class only
 * adds what the Agenda needs (pets, appointments, packages) and lets the Agenda have its own access rules.
 */
class Client extends Customer
{
    /** Relations keep the column of the shared table ("customer_id"), not "client_id". */
    public function getForeignKey(): string
    {
        return 'customer_id';
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'customer_id')->orderByDesc('is_active')->orderBy('name');
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'customer_id')->orderByDesc('starts_at');
    }

    /**
     * @return HasMany<ClientPackage, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(ClientPackage::class, 'customer_id')->orderByDesc('sold_on');
    }

    public function noShows(): int
    {
        return Appointment::query()->where('customer_id', $this->getKey())->where('status', AppointmentStatus::NoShow->value)->count();
    }

    /** Has missed appointments at least as many times as the company's limit (a flag on the agenda). */
    public function isFlagged(?int $noShows = null): bool
    {
        $limit = AgendaSetting::current()->no_show_flag;

        return $limit > 0 && ($noShows ?? $this->noShows()) >= $limit;
    }
}
