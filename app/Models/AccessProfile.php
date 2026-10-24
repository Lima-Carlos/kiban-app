<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Access profile of the company ("Logistics", "HR", "Driver"...): the role in each module. Choosing it when
 * adding a person fills their access in one click; it can still be adjusted for that person.
 */
class AccessProfile extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['name', 'description', 'roles', 'suggestion'];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
        ];
    }

    public function roleFor(string $module): ?string
    {
        $role = $this->roles[$module] ?? null;

        return filled($role) && $role !== 'none' ? (string) $role : null;
    }
}
