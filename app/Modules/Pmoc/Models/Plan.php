<?php

namespace App\Modules\Pmoc\Models;

use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\Site;
use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A preventive maintenance plan (PMOC in Brazil) of a customer's address: its equipment, the responsible
 * technician, how often the visits happen and the next one. Each visit becomes a Campo work order.
 */
class Plan extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    /** Months between visits the plan may have. */
    public const FREQUENCIES = [1, 2, 3, 6, 12];

    protected $table = 'pm_plans';

    protected $fillable = ['customer_id', 'site_id', 'name', 'responsible_name', 'responsible_registration', 'area_m2', 'occupants', 'service_type_id', 'frequency_months', 'lead_days', 'starts_on', 'next_due_on', 'notes', 'is_active'];

    protected $attributes = ['frequency_months' => 1, 'lead_days' => 7, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'next_due_on' => 'date',
            'frequency_months' => 'integer',
            'lead_days' => 'integer',
            'area_m2' => 'integer',
            'occupants' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<ServiceType, $this>
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * @return HasMany<Equipment, $this>
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class)->orderByDesc('due_on');
    }

    /**
     * @param  Builder<Plan>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Total cooling capacity of the active equipment (BTU/h), shown on the PMOC document. */
    public function thermalLoad(): int
    {
        return (int) $this->equipment->where('is_active', true)->sum('capacity_btu');
    }
}
