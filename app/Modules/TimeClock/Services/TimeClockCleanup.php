<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Punch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * The punch photos are removed after 12 months to save space; the punch itself (time, place, number, chain)
 * is never deleted. Attachments of corrections (medical certificates) are kept.
 * The manager is warned once a month about what will be removed in the next 30 days.
 */
class TimeClockCleanup
{
    public const PHOTO_MONTHS = 12;

    public const WARN_DAYS = 30;

    public function cutoff(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->subMonths(self::PHOTO_MONTHS);
    }

    /** Removes the old photos of the current company. Returns how many. */
    public function run(): int
    {
        $removed = 0;

        Punch::query()->where('created_at', '<', $this->cutoff())->whereNotNull('photo_path')->chunkById(200, function ($punches) use (&$removed): void {
            foreach ($punches as $punch) {
                Storage::disk('local')->delete($punch->photo_path);
                $punch->photo_path = null;
                $punch->save();
                $removed++;
            }
        });

        return $removed;
    }

    /** How many photos will be removed in the next days (for the warning). */
    public function upcoming(int $days = self::WARN_DAYS): int
    {
        return Punch::query()->where('created_at', '<', $this->cutoff(CarbonImmutable::now()->addDays($days)))->whereNotNull('photo_path')->count();
    }
}
