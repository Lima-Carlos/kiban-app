<?php

namespace App\Modules\Expenses\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of expense (toll, parking, meal...): the value above which a claim is flagged and whether the receipt
 * is required. Ready ones are created the first time; the company renames, changes or turns them off. A ready one
 * keeps no name of its own (null) until renamed, so it shows in the language of whoever is looking.
 */
class Category extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    /** Ready categories: key => [receipt required, Frota category]. */
    public const DEFAULTS = [
        'toll' => [true, 'toll'],
        'parking' => [true, 'parking'],
        'fuel' => [true, null],
        'meal' => [true, null],
        'lodging' => [true, null],
        'ride' => [true, null],
        'material' => [true, null],
        'mileage' => [false, null],
        'other' => [false, 'other'],
    ];

    protected $table = 'ex_categories';

    protected $fillable = ['key', 'name', 'limit_cents', 'receipt_required', 'fleet_category', 'is_active', 'sort', 'per_km_cents'];

    protected $attributes = ['receipt_required' => true, 'is_active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['limit_cents' => 'integer', 'per_km_cents' => 'integer', 'receipt_required' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    protected static function booted(): void
    {
        // Saving the ready name (in any language) keeps it translated instead of freezing that language.
        static::saving(function (Category $category): void {
            $name = $category->getAttributes()['name'] ?? null;

            if ($category->key !== null && $name !== null && in_array(trim($name), static::readyNames($category->key), true)) {
                $category->attributes['name'] = null;
            }
        });
    }

    /** @return list<string> */
    public static function readyNames(string $key): array
    {
        return array_map(fn (string $locale): string => __('expenses.defaults.'.$key, [], $locale), array_keys(config('kiban.locales')));
    }

    /** @return Attribute<string, string|null> */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): string => $value ?? ($this->key !== null ? __('expenses.defaults.'.$this->key) : ''),
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /** Mileage with one's own vehicle: the value is km × per_km_cents. */
    public function isMileage(): bool
    {
        return $this->key === 'mileage';
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
