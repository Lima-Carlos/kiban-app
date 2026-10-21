<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily: warnings of the bell older than 90 days are removed (they are reminders, not history).
 * The "already sent" keys stay a year, so a routine never repeats an old warning.
 */
class CleanupNotices extends Command
{
    public const KEEP_DAYS = 90;

    protected $signature = 'kiban:notices-cleanup';

    protected $description = 'Remove old warnings of the bell';

    public function handle(): int
    {
        $notices = DB::table('notifications')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        DB::table('notice_log')->where('created_at', '<', now()->subYear())->delete();

        $this->components->info(__('app.notices.cleaned', ['count' => $notices]));

        return self::SUCCESS;
    }
}
