<?php

namespace App\Modules\Field\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** The answer to one item of a work order's checklist (by the item's fixed key). The latest answer wins. */
class OrderAnswer extends Model
{
    use BelongsToTenant;

    protected $table = 'fd_order_answers';

    protected $fillable = ['order_id', 'item_key', 'value', 'answered_at'];

    protected function casts(): array
    {
        return ['answered_at' => 'immutable_datetime'];
    }
}
