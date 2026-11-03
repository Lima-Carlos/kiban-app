<?php

namespace App\Modules\Stock\Models;

use App\Modules\Field\Models\Material;
use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An item kept in stock (cable, connector, router...): unit, the minimum to warn about, whether each unit is
 * tracked by serial number, and the Campo material it is (so what a technician uses leaves their stock).
 */
class Item extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'es_items';

    protected $fillable = ['name', 'sku', 'unit', 'min_quantity', 'serialized', 'material_id', 'is_active'];

    protected $attributes = ['unit' => 'un', 'min_quantity' => 0, 'serialized' => false, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'float',
            'serialized' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Balance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function total(): float
    {
        return (float) $this->balances()->sum('quantity');
    }

    /** @return array<int, string> */
    public static function options(bool $serialized = false): array
    {
        return static::query()->active()->when($serialized, fn (Builder $query) => $query->where('serialized', true))->orderBy('name')->pluck('name', 'id')->all();
    }
}
