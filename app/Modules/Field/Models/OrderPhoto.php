<?php

namespace App\Modules\Field\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A photo of a work order, taken by the technician (of a checklist item, or a free one), with where and when. */
class OrderPhoto extends Model
{
    use BelongsToTenant;

    protected $table = 'fd_order_photos';

    protected $fillable = ['order_id', 'item_key', 'path', 'latitude', 'longitude', 'taken_at', 'client_id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'taken_at' => 'immutable_datetime',
        ];
    }
}
