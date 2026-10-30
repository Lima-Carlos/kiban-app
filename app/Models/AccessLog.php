<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One access to the application (Marco Civil): who, which company, IP, source port, device, first and last moment of
 * the session on that day. Visible only to Kiban (no screen in the systems); exported on a court order with
 * "php artisan kiban:access-logs". Not tenant-scoped on purpose: the records belong to Kiban, not to the company.
 */
class AccessLog extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'tenant_subdomain', 'user_id', 'user_email', 'session_hash', 'day', 'ip', 'port', 'user_agent', 'first_at', 'last_at'];

    protected function casts(): array
    {
        return ['day' => 'date', 'first_at' => 'datetime', 'last_at' => 'datetime'];
    }

    /** Older than the retention period go away with "php artisan model:prune" (scheduled daily). */
    public function prunable(): Builder
    {
        return static::query()->where('day', '<', now()->subDays(static::retentionDays())->toDateString());
    }

    /** Never less than 6 months (the legal minimum), whatever the setting says. */
    public static function retentionDays(): int
    {
        return max(183, (int) config('kiban.access_log_days'));
    }
}
