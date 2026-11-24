<?php

namespace App\Modules\Pedidos\Models;

use App\People\Person;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery run: the orders that left together with one driver, in the suggested visiting order
 * (Order::run_position). Done when every order of it was delivered or cancelled.
 */
class Run extends Model
{
    use BelongsToTenant;

    public const OPEN = 'open';

    public const DONE = 'done';

    protected $table = 'pd_runs';

    protected $fillable = ['driver_id', 'status', 'distance_m', 'started_at', 'finished_at', 'created_by'];

    protected $attributes = ['status' => self::OPEN];

    protected function casts(): array
    {
        return ['distance_m' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'driver_id');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderBy('run_position');
    }
}
