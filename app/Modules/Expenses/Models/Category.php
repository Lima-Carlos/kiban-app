<?php

namespace App\Modules\Expenses\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of expense (toll, parking, meal...): the value above which a claim is flagged and whether the receipt
 * is required. Ready ones are created the first time; the company renames, changes or turns them off.
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
        'other' => [false, 'other'],
    ];

    protected $table = 'ex_categories';

    protected $fillable = ['key', 'name', 'limit_cents', 'receipt_required', 'fleet_category', 'is_active', 'sort'];

    protected $attributes = ['receipt_required' => true, 'is_active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['limit_cents' => 'integer', 'receipt_required' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
