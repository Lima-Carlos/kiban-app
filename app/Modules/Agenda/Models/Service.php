<?php

namespace App\Modules\Agenda\Models;

use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A service of the company (bath, grooming, hydration...): time and price, optionally different per size.
 * Add-ons only go together with a main service; "leva e traz" is the pick-up service.
 */
class Service extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'ag_services';

    protected $fillable = ['name', 'template_key', 'description', 'duration_minutes', 'price_cents', 'size_prices', 'is_addon', 'is_pickup', 'online', 'color', 'sort', 'is_active'];

    protected $attributes = ['is_active' => true, 'online' => true, 'is_addon' => false, 'is_pickup' => false];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price_cents' => 'integer',
            'size_prices' => 'array',
            'is_addon' => 'boolean',
            'is_pickup' => 'boolean',
            'online' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @param  Builder<Service>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Minutes for this size (the base time when the size has none). */
    public function durationFor(?ItemSize $size): int
    {
        $value = $size ? ($this->size_prices[$size->value]['duration_minutes'] ?? null) : null;

        return (int) (filled($value) ? $value : $this->duration_minutes);
    }

    /** Price in cents for this size (the base price when the size has none). */
    public function priceFor(?ItemSize $size): int
    {
        $value = $size ? ($this->size_prices[$size->value]['price_cents'] ?? null) : null;

        return (int) (filled($value) ? $value : $this->price_cents);
    }

    /** Has a price or time per size. */
    public function variesBySize(): bool
    {
        return collect((array) $this->size_prices)->contains(fn ($values): bool => is_array($values) && (filled($values['price_cents'] ?? null) || filled($values['duration_minutes'] ?? null)));
    }
}
