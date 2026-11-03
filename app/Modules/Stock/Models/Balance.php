<?php

namespace App\Modules\Stock\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of an item there is in a place. Changed only by StockService (each change is a Movement).
 */
class Balance extends Model
{
    use BelongsToTenant;

    protected $table = 'es_balances';

    protected $fillable = ['item_id', 'location_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
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
}
