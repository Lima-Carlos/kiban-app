<?php

namespace App\Modules\Field\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service address of a customer, with its point on the map. The point comes from the phone's GPS ("Use my
 * location"), from the spreadsheet, from the address search (OpenStreetMap, in the background) or from the
 * technician on the first visit. A point typed or confirmed by a person is never replaced by the search.
 */
class Site extends Model
{
    use BelongsToTenant;

    /** Point typed or picked on the form (also "Use my location"). */
    public const TYPED = 'typed';

    /** Latitude and longitude came in the spreadsheet. */
    public const IMPORT = 'import';

    /** Found by the address search (approximate). */
    public const SEARCH = 'search';

    /** Confirmed by the technician at the place (part 4). */
    public const TECHNICIAN = 'technician';

    public const PENDING = 'pending';

    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    protected $table = 'fd_sites';

    protected $fillable = ['label', 'postal_code', 'street', 'number', 'complement', 'district', 'city', 'state', 'country', 'reference', 'contact_name', 'contact_phone', 'latitude', 'longitude', 'is_main'];

    protected $attributes = ['is_main' => false];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_main' => 'boolean',
            'located_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Site $site): void {
            // An empty field of the form is "no point", never 0,0 (the middle of the ocean).
            foreach (['latitude', 'longitude'] as $field) {
                if (($site->getAttributes()[$field] ?? null) === '') {
                    $site->setAttribute($field, null);
                }
            }

            // A point typed on the form (or "Use my location") is the person's: it is never searched again.
            if ($site->isDirty(['latitude', 'longitude']) && $site->hasPoint() && ! $site->isDirty('location_source')) {
                $site->location_source = self::TYPED;
            }

            if (! $site->hasPoint()) {
                $site->location_source = null;
            }

            // Without a point, the address is searched on the map in the background (when there is an address).
            if (! $site->hasPoint() && $site->hasAddress() && ($site->locate_status === null || $site->isDirty(['street', 'number', 'district', 'city', 'state', 'postal_code', 'country']))) {
                $site->locate_status = self::PENDING;
            }

            if ($site->hasPoint() && $site->locate_status === self::PENDING) {
                $site->locate_status = null;
            }
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @param  Builder<Site>  $query
     */
    public function scopeWaitingSearch(Builder $query): void
    {
        $query->where('locate_status', self::PENDING);
    }

    public function hasPoint(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function hasAddress(): bool
    {
        return filled($this->street) && filled($this->city);
    }

    /** "Street, number - complement - district, city/state". */
    public function address(): string
    {
        $street = collect([$this->street, $this->number])->filter()->implode(', ');
        $place = collect([$this->city, $this->state])->filter()->implode('/');

        return collect([$street, $this->complement, $this->district, $place])->filter()->implode(' - ');
    }
}
