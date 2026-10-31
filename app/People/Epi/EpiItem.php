<?php

namespace App\People\Epi;

use App\People\Documents\PersonDocument;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An item of the company's catalog: a PPE (with the approval certificate "CA" and its validity in Brazil) or a
 * tool / equipment, with the suggested useful life after the delivery, the size, an internal code, the unit cost
 * and a simple stock (null = not controlled). Ready items come from EpiTemplates (name translated by "key").
 * An item is turned off, never deleted (deliveries point to it).
 */
class EpiItem extends Model
{
    use BelongsToTenant;

    public const LIFE_UNITS = ['days', 'months'];

    /** CA warnings: this many days before it expires (then 7 days before and on the day). */
    public const CA_ALERT_DAYS = 30;

    protected $table = 'epi_items';

    protected $fillable = [
        'key', 'name', 'kind', 'size', 'code', 'ca_number', 'ca_expires_on', 'life_amount', 'life_unit',
        'unit_cost_cents', 'stock', 'min_stock', 'reusable', 'is_active', 'sort',
    ];

    protected $attributes = ['life_unit' => 'months', 'reusable' => false, 'is_active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'kind' => EpiKind::class,
            'ca_expires_on' => 'date',
            'life_amount' => 'integer',
            'unit_cost_cents' => 'integer',
            'stock' => 'integer',
            'min_stock' => 'integer',
            'low_stock_notified_at' => 'datetime',
            'reusable' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return HasMany<EpiDeliveryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(EpiDeliveryLine::class, 'item_id');
    }

    /** Name in the language of who reads it (ready items) or as the company typed it. */
    public function label(): string
    {
        if (filled($this->name)) {
            return (string) $this->name;
        }

        return $this->key !== null ? __('epi.templates.'.$this->key) : '—';
    }

    /** "Botina de segurança · 40" (the size, when there is one). */
    public function fullLabel(): string
    {
        return $this->label().(filled($this->size) ? ' · '.$this->size : '');
    }

    /** "30 dias", "24 meses" or null (no automatic replacement). */
    public function lifeText(): ?string
    {
        if ($this->life_amount === null) {
            return null;
        }

        return trans_choice('epi.life.'.($this->life_unit === 'days' ? 'days' : 'months'), $this->life_amount, ['count' => $this->life_amount]);
    }

    /** When an item delivered on this day should be replaced (null = no automatic replacement). */
    public function replaceOn(CarbonInterface|string $deliveredOn): ?CarbonImmutable
    {
        if ($this->life_amount === null || $this->life_amount < 1) {
            return null;
        }

        $day = CarbonImmutable::parse($deliveredOn instanceof CarbonInterface ? $deliveredOn->toDateString() : $deliveredOn);

        return $this->life_unit === 'days' ? $day->addDays($this->life_amount) : $day->addMonthsNoOverflow($this->life_amount);
    }

    public function tracksStock(): bool
    {
        return $this->stock !== null;
    }

    /** At or below the minimum (only when the stock and the minimum are set). */
    public function isLowStock(): bool
    {
        return $this->stock !== null && $this->min_stock !== null && $this->stock <= $this->min_stock;
    }

    /** Days until the CA expires (negative = expired), null without a date. */
    public function caDaysLeft(?CarbonInterface $today = null): ?int
    {
        if ($this->ca_expires_on === null) {
            return null;
        }

        $today = CarbonImmutable::parse(($today ?? static::today())->toDateString());

        return (int) $today->diffInDays(CarbonImmutable::parse($this->ca_expires_on->toDateString()), false);
    }

    public function caExpired(?CarbonInterface $today = null): bool
    {
        $days = $this->caDaysLeft($today);

        return $days !== null && $days < 0;
    }

    /** "CA vencido", "CA vence em 12 dias" (within 30 days) or null. */
    public function caWarning(?CarbonInterface $today = null): ?string
    {
        $days = $this->caDaysLeft($today);

        return match (true) {
            $days === null || $days > self::CA_ALERT_DAYS => null,
            $days < 0 => __('epi.ca.expired'),
            $days === 0 => __('epi.ca.today'),
            default => trans_choice('epi.ca.in_days', $days, ['days' => $days]),
        };
    }

    /** Today in the company's time zone. */
    public static function today(): CarbonImmutable
    {
        return PersonDocument::today();
    }

    /**
     * @param  Builder<EpiItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<EpiItem>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /**
     * @param  Builder<EpiItem>  $query
     * @param  array<int, string>  $kinds
     */
    public function scopeOfKinds(Builder $query, array $kinds): void
    {
        $query->whereIn($query->qualifyColumn('kind'), $kinds);
    }
}
