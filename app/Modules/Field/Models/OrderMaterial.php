<?php

namespace App\Modules\Field\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A material used on a work order, with the catalog's price at that moment. */
class OrderMaterial extends Model
{
    use BelongsToTenant;

    protected $table = 'fd_order_materials';

    protected $fillable = ['order_id', 'material_id', 'description', 'unit', 'quantity', 'unit_price_cents', 'client_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price_cents' => 'integer',
        ];
    }
}
