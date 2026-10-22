<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Models\Expense;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\Maintenance;
use App\Modules\Fleet\Models\ServiceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Old files are removed to save space; the records (numbers, dates, who, what) are never deleted.
 *
 * - photos (inspections, dashboard of the fuel-ups, requests): after 6 months;
 * - receipts and invoices (fuel-ups, maintenance, expenses): after 5 years;
 * - vehicle photo and vehicle documents: kept.
 *
 * The manager is warned once a month about what will be removed in the next 30 days.
 */
class FleetCleanup
{
    public const PHOTO_MONTHS = 6;

    public const RECEIPT_YEARS = 5;

    public const WARN_DAYS = 30;

    /**
     * Files in simple columns: model, date column, file column, kind.
     *
     * @return array<int, array{0: class-string<Model>, 1: string, 2: string, 3: string}>
     */
    protected function columns(): array
    {
        return [
            [FuelUp::class, 'created_at', 'odometer_photo_path', 'photo'],
            [ServiceRequest::class, 'created_at', 'photo_path', 'photo'],
            [FuelUp::class, 'created_at', 'receipt_path', 'receipt'],
            [Maintenance::class, 'performed_on', 'invoice_path', 'receipt'],
            [Expense::class, 'spent_on', 'receipt_path', 'receipt'],
        ];
    }

    public function cutoff(string $kind, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();

        return $kind === 'photo' ? $now->subMonths(self::PHOTO_MONTHS) : $now->subYears(self::RECEIPT_YEARS);
    }

    /** Removes the old files of the current company. Returns how many files. */
    public function run(): int
    {
        $removed = 0;

        foreach ($this->columns() as [$model, $date, $column, $kind]) {
            $model::query()->where($date, '<', $this->cutoff($kind))->whereNotNull($column)->chunkById(200, function ($records) use ($column, &$removed): void {
                foreach ($records as $record) {
                    Storage::disk('local')->delete($record->{$column});
                    $record->forceFill([$column => null])->saveQuietly();
                    $removed++;
                }
            });
        }

        $this->inspectionsQuery($this->cutoff('photo'))->chunkById(200, function ($inspections) use (&$removed): void {
            foreach ($inspections as $inspection) {
                $files = $this->inspectionFiles($inspection);
                Storage::disk('local')->delete($files);
                $inspection->forceFill([
                    'photos' => [],
                    'answers' => array_map(fn (array $answer): array => ['photo' => null] + $answer, (array) $inspection->answers),
                ])->saveQuietly();
                $removed += count($files);
            }
        });

        return $removed;
    }

    /** How many files will be removed in the next days (for the warning). */
    public function upcoming(int $days = self::WARN_DAYS): int
    {
        $later = CarbonImmutable::now()->addDays($days);
        $count = 0;

        foreach ($this->columns() as [$model, $date, $column, $kind]) {
            $count += $model::query()->where($date, '<', $this->cutoff($kind, $later))->whereNotNull($column)->count();
        }

        foreach ($this->inspectionsQuery($this->cutoff('photo', $later))->get() as $inspection) {
            $count += count($this->inspectionFiles($inspection));
        }

        return $count;
    }

    /**
     * @return Builder<Inspection>
     */
    protected function inspectionsQuery(CarbonImmutable $cutoff): Builder
    {
        // Photos already removed are saved as an empty list.
        return Inspection::query()->where('performed_at', '<', $cutoff)->whereRaw("photos::text not in ('[]', '{}')");
    }

    /**
     * @return array<int, string>
     */
    protected function inspectionFiles(Inspection $inspection): array
    {
        return array_values(array_filter([
            ...array_values((array) $inspection->photos),
            ...array_map(fn (array $answer): ?string => $answer['photo'] ?? null, (array) $inspection->answers),
        ]));
    }
}
