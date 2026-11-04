<?php

namespace App\Modules\Pmoc\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One piece of equipment of a plan (e.g. "Room 101 · evaporator"): kind, brand, model, serial, capacity and gas.
 */
class Equipment extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    /** Kinds of equipment (air conditioning first; "other" for any equipment with preventive maintenance). */
    public const KINDS = ['split', 'cassette', 'floor_ceiling', 'window', 'duct', 'vrf', 'chiller', 'fan_coil', 'other'];

    public const REFRIGERANTS = ['R410A', 'R32', 'R22', 'R134A', 'R407C', 'other'];

    protected $table = 'pm_equipment';

    protected $fillable = ['plan_id', 'tag', 'kind', 'room', 'brand', 'model', 'serial', 'capacity_btu', 'refrigerant', 'installed_on', 'is_active'];

    protected $attributes = ['kind' => 'split', 'is_active' => true];

    protected $hidden = ['public_token'];

    protected static function booted(): void
    {
        static::creating(function (Equipment $equipment): void {
            $equipment->public_token ??= Str::random(48);
        });
    }

    /** The page the QR label opens: no login, on the company's address. */
    public function publicUrl(): string
    {
        return route('pmoc.public', ['token' => $this->public_token]);
    }

    protected function casts(): array
    {
        return [
            'capacity_btu' => 'integer',
            'installed_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function label(): string
    {
        return collect([$this->tag, $this->room])->filter()->implode(' · ');
    }
}
