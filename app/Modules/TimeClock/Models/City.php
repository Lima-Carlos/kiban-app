<?php

namespace App\Modules\TimeClock\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A city of the company (for the municipal holidays). The IBGE code (Brazil) finds the suggested holidays;
 * the company always confirms them.
 */
class City extends Model
{
    use BelongsToTenant;

    protected $table = 'tc_cities';

    protected $fillable = ['name', 'state', 'ibge_code'];

    public function label(): string
    {
        return $this->state ? $this->name.' - '.$this->state : $this->name;
    }

    /**
     * @return HasMany<Holiday, $this>
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }
}
