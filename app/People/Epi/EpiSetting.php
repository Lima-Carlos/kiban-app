<?php

namespace App\People\Epi;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The company's own acknowledgment text for the deliveries (null = the standard text, in the language of who
 * signs). The text actually shown is frozen in each signed delivery.
 */
class EpiSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'epi_settings';

    protected $fillable = ['terms'];

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }
}
