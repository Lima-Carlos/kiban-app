<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Fleet\Enums\ExpenseCategory;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Other fleet expenses (vehicle tax, licensing, insurance, fines, tolls, parking...), with the receipt.
 */
class Expense extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_expenses';

    protected $fillable = ['vehicle_id', 'category', 'description', 'amount_cents', 'spent_on', 'receipt_path'];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount_cents' => 'integer',
            'spent_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
