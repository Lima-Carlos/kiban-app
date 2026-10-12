<?php

namespace App\Modules\Fleet\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Outside workshop (garage) the company uses. The company's own mechanic is a person with the "mechanic" role.
 */
class Workshop extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_workshops';

    protected $fillable = ['name', 'document', 'phone', 'address', 'city', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<Maintenance, $this>
     */
    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class);
    }
}
