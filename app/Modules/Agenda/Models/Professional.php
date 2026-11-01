<?php

namespace App\Modules\Agenda\Models;

use App\Modules\ReportsFirstSteps;
use App\People\Person;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone of the "People" screen who attends on the agenda (bather, groomer...), with or without a login.
 * Hours: the person's Ponto schedule when the company has the Ponto and the person has one; otherwise the
 * weekly hours kept here (same shape as the Ponto schedules). Active professionals count in the plan limit.
 */
class Professional extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    public const COLORS = ['#044299', '#0F766E', '#B45309', '#7C3AED', '#BE123C', '#15803D', '#0369A1', '#A16207'];

    protected $table = 'ag_professionals';

    protected $fillable = ['person_id', 'color', 'week', 'follows_time_clock', 'commission_bp', 'online', 'is_active', 'sort'];

    protected $attributes = ['is_active' => true, 'online' => true, 'follows_time_clock' => true, 'commission_bp' => 0];

    protected function casts(): array
    {
        return [
            'week' => 'array',
            'follows_time_clock' => 'boolean',
            'commission_bp' => 'integer',
            'online' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Services this professional does (none = all of them), with the commission of each one.
     *
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'ag_professional_services', 'professional_id', 'service_id')
            ->withPivot(['tenant_id', 'commission_bp'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<TimeOff, $this>
     */
    public function timeOff(): HasMany
    {
        return $this->hasMany(TimeOff::class)->orderBy('starts_at');
    }

    /**
     * @param  Builder<Professional>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function name(): string
    {
        return (string) $this->person?->name;
    }

    public function displayColor(): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->color) ? $this->color : self::COLORS[$this->getKey() % count(self::COLORS)];
    }

    /** Does this service (no list = every service). */
    public function does(Service $service): bool
    {
        $ids = $this->relationLoaded('services') ? $this->services->modelKeys() : $this->services()->pluck('ag_services.id')->all();

        return $ids === [] || in_array($service->getKey(), $ids, true);
    }

    /** Commission of a service in hundredths of a percent (3000 = 30%). */
    public function commissionFor(?Service $service): int
    {
        if ($service !== null) {
            $own = $this->services()->where('ag_services.id', $service->getKey())->first()?->pivot?->commission_bp;

            if ($own !== null) {
                return (int) $own;
            }
        }

        return (int) $this->commission_bp;
    }

    /**
     * Sets the services this professional does and the commission of each one (null = the default one).
     *
     * @param  array<int, array{service_id: int|string, commission_bp?: int|string|null}>  $rows
     */
    public function syncServices(array $rows): void
    {
        $sync = [];

        foreach ($rows as $row) {
            if (blank($row['service_id'] ?? null) || ! Service::query()->whereKey($row['service_id'])->exists()) {
                continue;
            }

            $sync[(int) $row['service_id']] = [
                'tenant_id' => $this->tenant_id,
                'commission_bp' => filled($row['commission_bp'] ?? null) ? (int) $row['commission_bp'] : null,
            ];
        }

        $this->services()->sync($sync);
    }
}
