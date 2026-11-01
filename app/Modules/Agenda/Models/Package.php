<?php

namespace App\Modules\Agenda\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A package for sale, e.g. "4 baths" valid for 60 days.
 */
class Package extends Model
{
    use BelongsToTenant;

    protected $table = 'ag_packages';

    protected $fillable = ['name', 'service_id', 'quantity', 'valid_days', 'price_cents', 'is_active'];

    protected $attributes = ['is_active' => true, 'valid_days' => 60];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'valid_days' => 'integer', 'price_cents' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
