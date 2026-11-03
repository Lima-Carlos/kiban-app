<?php

namespace App\Modules\Stock\Models;

use App\Models\User;
use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place where items are kept: a warehouse, or the stock a technician carries in the vehicle.
 */
class Location extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    public const WAREHOUSE = 'warehouse';

    public const TECHNICIAN = 'technician';

    protected $table = 'es_locations';

    protected $fillable = ['name', 'kind', 'user_id', 'is_active'];

    protected $attributes = ['kind' => self::WAREHOUSE, 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Location>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return static::query()->active()->orderBy('kind', 'desc')->orderBy('name')->pluck('name', 'id')->all();
    }
}
