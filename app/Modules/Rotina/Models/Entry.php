<?php

namespace App\Modules\Rotina\Models;

use App\Models\User;
use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hours worked by a person on a project in a day (typed, or from the stopwatch).
 */
class Entry extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'ro_entries';

    protected $fillable = ['user_id', 'project_id', 'worked_on', 'minutes', 'note', 'started_at'];

    protected function casts(): array
    {
        return ['worked_on' => 'date', 'minutes' => 'integer', 'started_at' => 'datetime'];
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

    /**
     * @param  Builder<Entry>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereNotNull('started_at');
    }

    /** Stops the stopwatch: the elapsed time becomes minutes (at least 1). */
    public function stop(): void
    {
        if ($this->started_at === null) {
            return;
        }

        $this->update(['minutes' => $this->minutes + max(1, (int) round($this->started_at->diffInSeconds(now()) / 60)), 'started_at' => null]);
    }

    /** "2 h 30 min" */
    public static function format(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours > 0 ? trim($hours.' h '.($rest > 0 ? $rest.' min' : '')) : $rest.' min';
    }
}
