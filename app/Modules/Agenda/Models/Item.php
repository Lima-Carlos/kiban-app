<?php

namespace App\Modules\Agenda\Models;

use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Agenda\Enums\Species;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What is attended for a client: a pet in a pet shop ("kind" = pet). The table is generic on purpose: car
 * detailing will use the same record for the car (kind "vehicle"), with the size for prices and times.
 */
class Item extends Model
{
    use BelongsToTenant;

    public const PET = 'pet';

    public const COATS = ['short', 'medium', 'long', 'curly', 'double'];

    protected $table = 'ag_items';

    protected $fillable = ['customer_id', 'kind', 'name', 'species', 'breed', 'size', 'coat', 'birth_date', 'notes', 'photo_path', 'is_active'];

    protected $attributes = ['kind' => self::PET, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'species' => Species::class,
            'size' => ItemSize::class,
            'birth_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'customer_id');
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** "Thor (Shih-tzu, P)". */
    public function label(): string
    {
        $details = collect([$this->breed, $this->size?->short()])->filter()->implode(', ');

        return $details === '' ? $this->name : $this->name.' ('.$details.')';
    }
}
