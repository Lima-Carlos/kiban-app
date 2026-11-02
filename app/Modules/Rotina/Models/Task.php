<?php

namespace App\Modules\Rotina\Models;

use App\Models\User;
use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A task of a person's day.
 */
class Task extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'ro_tasks';

    protected $fillable = ['user_id', 'project_id', 'title', 'due_on', 'done_at'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'done_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
