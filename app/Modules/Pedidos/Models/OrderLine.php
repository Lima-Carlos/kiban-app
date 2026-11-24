<?php

namespace App\Modules\Pedidos\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of an order: the item (name and price copied from the menu), how many and the free text of the line
 * ("sem cebola", "meio a meio calabresa e mussarela").
 */
class OrderLine extends Model
{
    use BelongsToTenant;

    protected $table = 'pd_order_lines';

    protected $fillable = ['order_id', 'menu_item_id', 'name', 'unit_price_cents', 'quantity', 'notes', 'total_cents'];

    protected function casts(): array
    {
        return ['unit_price_cents' => 'integer', 'quantity' => 'integer', 'total_cents' => 'integer', 'menu_item_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** "2× Pizza calabresa (sem cebola)". */
    public function label(): string
    {
        return $this->quantity.'× '.$this->name.(filled($this->notes) ? ' ('.$this->notes.')' : '');
    }
}
