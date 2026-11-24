<?php

namespace App\Modules\Pedidos\Models;

use App\People\Person;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person of the "People" screen who works in the kitchen and/or delivers (with or without a login). They are the
 * one-tap buttons "who prepared" and "who delivered".
 */
class Staff extends Model
{
    use BelongsToTenant;

    protected $table = 'pd_staff';

    protected $fillable = ['person_id', 'cooks', 'delivers'];

    protected $attributes = ['cooks' => false, 'delivers' => false];

    protected function casts(): array
    {
        return ['cooks' => 'boolean', 'delivers' => 'boolean'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
