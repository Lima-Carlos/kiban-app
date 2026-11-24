<?php

namespace App\Modules\Pedidos\Models;

use App\Models\User;
use App\Modules\Field\Models\Site;
use App\Modules\Pedidos\Enums\Fulfillment;
use App\Modules\Pedidos\Enums\OrderSource;
use App\Modules\Pedidos\Enums\OrderStatus;
use App\Modules\Pedidos\Enums\PaymentMethod;
use App\People\Person;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order (by phone, WhatsApp, at the counter or written down from an app). Every change goes through
 * OrderService. The order keeps the address, names and prices as they were, so it never changes later.
 */
class Order extends Model
{
    use BelongsToTenant;

    protected $table = 'pd_orders';

    protected $fillable = [
        'number', 'customer_id', 'site_id', 'address', 'fulfillment', 'source', 'payment_method', 'change_for_cents', 'status', 'notes',
        'subtotal_cents', 'delivery_fee_cents', 'total_cents', 'prepared_by', 'delivered_by', 'run_id', 'run_position',
        'preparing_at', 'ready_at', 'out_at', 'delivered_at', 'cancelled_at', 'cancel_reason', 'created_by',
    ];

    protected $attributes = ['status' => 'new', 'fulfillment' => 'delivery', 'source' => 'phone'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => OrderStatus::class,
            'fulfillment' => Fulfillment::class,
            'source' => OrderSource::class,
            'payment_method' => PaymentMethod::class,
            'change_for_cents' => 'integer',
            'subtotal_cents' => 'integer',
            'delivery_fee_cents' => 'integer',
            'total_cents' => 'integer',
            'run_position' => 'integer',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'out_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'prepared_by');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'delivered_by');
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', OrderStatus::openValues());
    }

    public function isDelivery(): bool
    {
        return $this->fulfillment === Fulfillment::Delivery;
    }

    /** The name on the ticket: the customer's, or "Balcão" for a counter sale without a name. */
    public function customerName(): string
    {
        return $this->client?->name ?? __('pedidos.orders.no_customer');
    }

    /** Minutes from the order to the delivery (or until now, while open). Null when cancelled. */
    public function minutes(): ?int
    {
        if ($this->status === OrderStatus::Cancelled || $this->created_at === null) {
            return null;
        }

        return (int) $this->created_at->diffInMinutes($this->delivered_at ?? now());
    }

    /** "2× Pizza, 1× Refrigerante". */
    public function summary(): string
    {
        return $this->lines->map(fn (OrderLine $line): string => $line->quantity.'× '.$line->name)->implode(', ');
    }
}
