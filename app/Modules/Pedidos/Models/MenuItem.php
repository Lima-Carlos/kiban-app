<?php

namespace App\Modules\Pedidos\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An item of the menu (cardápio): name, category ("Pizzas", "Bebidas"...), price in cents and whether it is
 * offered now. The order keeps its own copy of the name and price, so changing the menu never changes old orders.
 */
class MenuItem extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'pd_menu_items';

    protected $fillable = ['name', 'category', 'price_cents', 'is_active', 'sort'];

    protected $attributes = ['is_active' => true, 'price_cents' => 0];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    /**
     * @param  Builder<MenuItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Categories already used by the company (for the form's suggestions).
     *
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return static::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
    }
}
