<?php

namespace App\Modules\Field\Models;

use App\Models\User;
use App\Modules\Field\Enums\OrderPriority;
use App\Modules\Field\Enums\OrderStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A work order (OS): the service of a type for a customer at one of their addresses, with its own number in the
 * company, the technician and the time on the agenda. The type's checklist is copied when it is opened.
 * Changes go only through WorkOrderService (each one is written in the order's history).
 */
class WorkOrder extends Model
{
    use BelongsToTenant;

    public const MANUAL = 'manual';

    public const QUOTE = 'quote';

    protected $table = 'fd_orders';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => OrderStatus::class,
            'priority' => OrderPriority::class,
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'price_cents' => 'integer',
            'checklist' => 'array',
            'signature_required' => 'boolean',
            'photo_required' => 'boolean',
            'checked_in_at' => 'immutable_datetime',
            'check_in_latitude' => 'float',
            'check_in_longitude' => 'float',
            'check_in_accuracy' => 'integer',
            'check_in_distance' => 'integer',
            'check_in_km' => 'integer',
            'signed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<OrderAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(OrderAnswer::class, 'order_id');
    }

    /**
     * @return HasMany<OrderPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(OrderPhoto::class, 'order_id')->orderBy('taken_at');
    }

    /**
     * @return HasMany<OrderMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(OrderMaterial::class, 'order_id')->orderBy('id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<ServiceType, $this>
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return HasMany<OrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class, 'order_id')->latest('id');
    }

    /**
     * @param  Builder<WorkOrder>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::pending()));
    }

    /** "OS 000123". */
    public function label(): string
    {
        return __('field.orders.label', ['number' => str_pad((string) $this->number, 6, '0', STR_PAD_LEFT)]);
    }

    public function endsAt(): ?CarbonImmutable
    {
        return $this->scheduled_at?->addMinutes($this->duration_minutes);
    }
}
