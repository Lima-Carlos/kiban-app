<?php

namespace App\People\Epi;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One item of a delivery (e.g. 2 pairs of gloves), with what the item was at that moment (name, CA, size, cost)
 * and when it should be replaced (useful life). Returns only add to "returned_quantity"; nothing else changes
 * after the delivery is signed.
 */
class EpiDeliveryLine extends Model
{
    use BelongsToTenant;

    /** "To replace soon": this many days before the replacement day. */
    public const SOON_DAYS = 7;

    protected $table = 'epi_delivery_lines';

    protected $fillable = ['delivery_id', 'item_id', 'quantity', 'item_name', 'kind', 'size', 'ca_number', 'ca_expires_on', 'unit_cost_cents', 'replace_on'];

    protected function casts(): array
    {
        return [
            'kind' => EpiKind::class,
            'quantity' => 'integer',
            'returned_quantity' => 'integer',
            'ca_expires_on' => 'date',
            'replace_on' => 'date',
            'unit_cost_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // After the signature, only the returned quantity moves (a return is its own record).
        static::updating(function (EpiDeliveryLine $line): void {
            $changed = array_diff(array_keys($line->getDirty()), ['returned_quantity', 'updated_at']);

            if ($changed !== [] && $line->delivery()->value('signed_at') !== null) {
                throw new LogicException('A signed delivery never changes.');
            }
        });

        static::deleting(function (EpiDeliveryLine $line): void {
            if (! EpiDelivery::$purging && $line->delivery()->value('signed_at') !== null) {
                throw new LogicException('A signed delivery is never deleted.');
            }
        });
    }

    /**
     * @return BelongsTo<EpiDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(EpiDelivery::class, 'delivery_id');
    }

    /**
     * @return BelongsTo<EpiItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(EpiItem::class, 'item_id');
    }

    /**
     * @return HasMany<EpiReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(EpiReturn::class, 'line_id')->orderBy('id');
    }

    /** How many are still with the person. */
    public function openQuantity(): int
    {
        return max(0, $this->quantity - $this->returned_quantity);
    }

    /** "Luva de vaqueta · G" */
    public function label(): string
    {
        return $this->item_name.(filled($this->size) ? ' · '.$this->size : '');
    }

    /** Days until the replacement (0 = today, negative = late), null without a useful life. */
    public function daysLeft(?CarbonInterface $today = null): ?int
    {
        if ($this->replace_on === null) {
            return null;
        }

        $today = CarbonImmutable::parse(($today ?? EpiItem::today())->toDateString());

        return (int) $today->diffInDays(CarbonImmutable::parse($this->replace_on->toDateString()), false);
    }

    public function status(?CarbonInterface $today = null): LineStatus
    {
        if ($this->openQuantity() === 0) {
            return LineStatus::Returned;
        }

        $days = $this->daysLeft($today);

        return match (true) {
            $days === null => LineStatus::NoLife,
            $days <= 0 => LineStatus::Due,
            $days <= self::SOON_DAYS => LineStatus::Soon,
            default => LineStatus::Ok,
        };
    }

    /** "Trocar em 3 dias", "Trocar hoje", "Troca atrasada 5 dias"... */
    public function statusText(?CarbonInterface $today = null): string
    {
        $status = $this->status($today);
        $days = (int) $this->daysLeft($today);

        return match (true) {
            $status === LineStatus::Soon => trans_choice('epi.status.in_days', $days, ['days' => $days]),
            $status === LineStatus::Due && $days === 0 => __('epi.status.today'),
            $status === LineStatus::Due => trans_choice('epi.status.late_days', -$days, ['days' => -$days]),
            default => $status->getLabel(),
        };
    }

    /**
     * Lines still (partly) with the person.
     *
     * @param  Builder<EpiDeliveryLine>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereColumn($query->qualifyColumn('returned_quantity'), '<', $query->qualifyColumn('quantity'));
    }

    /**
     * @param  Builder<EpiDeliveryLine>  $query
     * @param  array<int, string>  $kinds
     */
    public function scopeOfKinds(Builder $query, array $kinds): void
    {
        $query->whereIn($query->qualifyColumn('kind'), $kinds);
    }
}
