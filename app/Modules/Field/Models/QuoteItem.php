<?php

namespace App\Modules\Field\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a quote: a type of service, a material of the catalog or a free description, with quantity, unit
 * and price. The total of the line is always quantity × price (in cents, rounded).
 */
class QuoteItem extends Model
{
    use BelongsToTenant;

    protected $table = 'fd_quote_items';

    protected $fillable = ['quote_id', 'service_type_id', 'material_id', 'description', 'quantity', 'unit', 'unit_price_cents', 'sort'];

    protected $attributes = ['quantity' => 1, 'unit' => 'un', 'unit_price_cents' => 0, 'total_cents' => 0, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (QuoteItem $item): void {
            $item->total_cents = (int) round((float) $item->quantity * (int) $item->unit_price_cents);
        });
    }

    /**
     * @return BelongsTo<ServiceType, $this>
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
