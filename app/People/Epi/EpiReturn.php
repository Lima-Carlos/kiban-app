<?php

namespace App\People\Epi;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An item that came back from a person (or was lost): how many, when, why and what happened to it (back to the
 * stock, discarded or lost). Kept as history; never changed.
 */
class EpiReturn extends Model
{
    use BelongsToTenant;

    protected $table = 'epi_returns';

    protected $fillable = ['line_id', 'quantity', 'returned_on', 'outcome', 'reason', 'notes'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'returned_on' => 'date',
            'outcome' => ReturnOutcome::class,
            'reason' => ReturnReason::class,
        ];
    }

    /**
     * @return BelongsTo<EpiDeliveryLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(EpiDeliveryLine::class, 'line_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
