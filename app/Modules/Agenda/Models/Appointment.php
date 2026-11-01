<?php

namespace App\Modules\Agenda\Models;

use App\Models\User;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Models\WorkOrder;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An appointment: a client (and the pet), a professional, the time and the services (main + add-ons) with the
 * price and commission of that moment. Every change goes through AppointmentBook (with the history).
 */
class Appointment extends Model
{
    use BelongsToTenant;

    public const DESK = 'desk';

    public const ONLINE = 'online';

    protected $table = 'ag_appointments';

    protected $fillable = ['notes'];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'size' => ItemSize::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'price_cents' => 'integer',
            'pickup' => 'boolean',
            'dropoff' => 'boolean',
            'confirmed_at' => 'immutable_datetime',
            'arrived_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'no_show_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'ready_sent_at' => 'immutable_datetime',
        ];
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
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return HasMany<AppointmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AppointmentLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<AppointmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AppointmentEvent::class)->orderByDesc('id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function pickupSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'pickup_site_id');
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function fieldOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'field_order_id');
    }

    /**
     * @return BelongsTo<ClientPackage, $this>
     */
    public function clientPackage(): BelongsTo
    {
        return $this->belongsTo(ClientPackage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopeHolding(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::holding());
    }

    /** "Banho + Hidratação". */
    public function servicesLabel(): string
    {
        return $this->lines->pluck('name')->implode(' + ');
    }

    public function minutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** "Thor" or the client's name when there is no pet. */
    public function title(): string
    {
        return $this->item?->name ?? $this->client?->name ?? '';
    }
}
