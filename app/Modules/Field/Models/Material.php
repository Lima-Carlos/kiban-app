<?php

namespace App\Modules\Field\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A material of the company's catalog (cable, connector, router, gas...): what the technician writes down as
 * used on a work order (part 4) and what goes into a quote (part 2b), with the unit and the price.
 */
class Material extends Model
{
    use BelongsToTenant;

    /** Units offered on the form (the company may type another one). */
    public const UNITS = ['un', 'm', 'kg', 'l', 'h', 'cx', 'pc'];

    protected $table = 'fd_materials';

    protected $fillable = ['name', 'code', 'unit', 'price_cents', 'is_active'];

    protected $attributes = ['unit' => 'un', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Material>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
