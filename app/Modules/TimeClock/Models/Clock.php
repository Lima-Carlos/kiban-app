<?php

namespace App\Modules\TimeClock\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Brazil: a time clock the company already has (REP-C: card, fingerprint or face; or another program). Its
 * punches arrive by its AFD file (AfdImport), identified by the serial number in the file header.
 */
class Clock extends Model
{
    use BelongsToTenant;

    public const REP_C = 1;

    public const REP_A = 2;

    public const REP_P = 3;

    protected $table = 'tc_clocks';

    protected $fillable = ['name', 'type', 'number'];

    protected function casts(): array
    {
        return [
            'type' => 'integer',
            'last_nsr' => 'integer',
            'last_import_at' => 'datetime',
        ];
    }
}
