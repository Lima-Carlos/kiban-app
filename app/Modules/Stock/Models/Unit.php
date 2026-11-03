<?php

namespace App\Modules\Stock\Models;

use App\Modules\Field\Models\Customer;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unit of a serialized item (e.g. the router with serial ABC123): in stock in a place, lent to a customer
 * (comodato), defective or lost. Changed only by StockService.
 */
class Unit extends Model
{
    use BelongsToTenant;

    public const IN_STOCK = 'stock';

    public const WITH_CUSTOMER = 'customer';

    public const DEFECTIVE = 'defective';

    public const LOST = 'lost';

    public const STATUSES = [self::IN_STOCK, self::WITH_CUSTOMER, self::DEFECTIVE, self::LOST];

    protected $table = 'es_units';

    protected $fillable = ['item_id', 'serial', 'mac', 'status', 'location_id', 'customer_id', 'customer_name', 'since_at'];

    protected $attributes = ['status' => self::IN_STOCK];

    protected function casts(): array
    {
        return ['since_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Where it is now: the place, or the customer. */
    public function whereabouts(): string
    {
        return match ($this->status) {
            self::IN_STOCK => (string) $this->location?->name,
            self::WITH_CUSTOMER => (string) ($this->customer?->displayName() ?? $this->customer_name),
            default => __('stock.units.statuses.'.$this->status),
        };
    }
}
