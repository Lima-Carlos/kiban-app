<?php

namespace App\Modules\Agenda\Models;

use App\Models\User;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A package sold to a client (optionally for one pet). Each appointment of the package's service can use it:
 * done appointments are "used", upcoming ones are "booked"; canceled and no-shows give the session back.
 */
class ClientPackage extends Model
{
    use BelongsToTenant;

    protected $table = 'ag_client_packages';

    protected $fillable = ['customer_id', 'item_id', 'package_id', 'service_id', 'name', 'quantity', 'price_cents', 'sold_on', 'expires_on', 'sold_by'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price_cents' => 'integer', 'sold_on' => 'immutable_date', 'expires_on' => 'immutable_date'];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** Sessions already done. */
    public function used(): int
    {
        return $this->appointments()->whereIn('status', [AppointmentStatus::Ready->value, AppointmentStatus::Delivered->value])->count();
    }

    /** Sessions taken: done + booked (not canceled, not a no-show). */
    public function taken(): int
    {
        return $this->appointments()->whereIn('status', AppointmentStatus::holding())->count();
    }

    public function left(): int
    {
        return max(0, $this->quantity - $this->taken());
    }

    public function isExpired(?CarbonImmutable $on = null): bool
    {
        return $this->expires_on->toDateString() < ($on ?? CarbonImmutable::now(AgendaSetting::timezone()))->toDateString();
    }

    /**
     * @param  Builder<ClientPackage>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->where('expires_on', '>=', CarbonImmutable::now(AgendaSetting::timezone())->toDateString());
    }

    /** "4 banhos · 2 de 4 usados · vence 30/12". */
    public function label(): string
    {
        return __('agenda.packages.label', [
            'name' => $this->name,
            'used' => $this->taken(),
            'total' => $this->quantity,
            'date' => $this->expires_on->translatedFormat(__('app.date_format')),
        ]);
    }
}
