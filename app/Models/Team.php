<?php

namespace App\Models;

use App\People\Person;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team or sector of the company (e.g. "Installation team", "Office"). Shared by the modules:
 * the time clock groups employees by team and the field service sends work orders to a team.
 */
class Team extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Person, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }
}
