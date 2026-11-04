<?php

namespace App\Modules\Rotina\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project (or client) the hours and tasks are counted on.
 */
class Project extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'ro_projects';

    protected $fillable = ['billable', 'hour_price_cents', 'name', 'client_name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'billable' => 'boolean',
            'hour_price_cents' => 'integer','is_active' => 'boolean'];
    }

    public function label(): string
    {
        return $this->client_name ? $this->client_name.' · '.$this->name : $this->name;
    }

    /**
     * @param  Builder<Project>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return HasMany<Entry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }
}
