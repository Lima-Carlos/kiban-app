<?php

namespace App\People\Epi;

use App\Models\User;
use App\People\Person;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A delivery of PPE and tools to a person (one or more items, a day and a reason), signed by the person with the
 * finger (on the manager's phone or later on their own). Once signed it never changes: a correction is a new
 * delivery or a return. Changes go through EpiService.
 */
class EpiDelivery extends Model
{
    use BelongsToTenant;

    /** Only EpiService::purgePerson (the system deletes the person's data) may remove signed deliveries. */
    public static bool $purging = false;

    protected $table = 'epi_deliveries';

    protected $fillable = ['person_id', 'delivered_on', 'reason', 'notes'];

    protected function casts(): array
    {
        return [
            'delivered_on' => 'date',
            'reason' => DeliveryReason::class,
            'signed_at' => 'datetime',
            'signed_on_own_device' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A signed delivery is frozen (the record of the NR-6): nothing in it changes.
        static::updating(function (EpiDelivery $delivery): void {
            if ($delivery->getOriginal('signed_at') !== null) {
                throw new LogicException('A signed delivery never changes.');
            }
        });

        static::deleting(function (EpiDelivery $delivery): void {
            if ($delivery->getOriginal('signed_at') !== null && ! static::$purging) {
                throw new LogicException('A signed delivery is never deleted.');
            }
        });
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return HasMany<EpiDeliveryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(EpiDeliveryLine::class, 'delivery_id')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    /**
     * @param  Builder<EpiDelivery>  $query
     */
    public function scopeUnsigned(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('signed_at'));
    }

    /** "Entrega de 30/10/2026" for headings. */
    public function title(): string
    {
        return __('epi.delivery.title', ['date' => $this->delivered_on->translatedFormat(__('app.date_format'))]);
    }
}
