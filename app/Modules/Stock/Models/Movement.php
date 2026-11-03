<?php

namespace App\Modules\Stock\Models;

use App\Models\User;
use App\Modules\Field\Models\WorkOrder;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every change of stock, never edited: entry, transfer between places, use on a work order, adjustment (count),
 * lending a unit to a customer, its return, and loss.
 */
class Movement extends Model
{
    use BelongsToTenant;

    public const KINDS = ['entry', 'transfer', 'use', 'adjust', 'lend', 'return', 'loss'];

    protected $table = 'es_movements';

    protected $fillable = ['item_id', 'kind', 'quantity', 'from_location_id', 'to_location_id', 'unit_id', 'work_order_id', 'order_material_id', 'customer_name', 'note', 'user_id', 'moved_at'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'moved_at' => 'datetime'];
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
    public function from(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
