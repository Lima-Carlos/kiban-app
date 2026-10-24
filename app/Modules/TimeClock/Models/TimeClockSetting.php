<?php

namespace App\Modules\TimeClock\Models;

use App\Modules\TimeClock\Enums\LaborProfile;
use App\Support\Country;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Time clock settings of the company: country and labor rule profile ("Brazil (CLT)" or "Custom").
 * Created with the country of the company's registration in the Core.
 */
class TimeClockSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'tc_settings';

    protected $fillable = ['country', 'labor_profile'];

    protected function casts(): array
    {
        return [
            'labor_profile' => LaborProfile::class,
            'configured_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        $country = Country::current();

        return static::query()->first() ?? static::create(['country' => $country, 'labor_profile' => LaborProfile::forCountry($country)]);
    }

    /** Brazilian rules: CPF and PIS required, official files. */
    public function isBrazil(): bool
    {
        return $this->labor_profile === LaborProfile::BrazilClt;
    }
}
