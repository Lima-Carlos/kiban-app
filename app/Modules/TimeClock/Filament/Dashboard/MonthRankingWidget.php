<?php

namespace App\Modules\TimeClock\Filament\Dashboard;

use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Services\TimeClockReports;
use Filament\Widgets\Widget;

/**
 * The people with the most overtime, the most absences and minutes short, and the largest hour bank balances
 * of the month (each name opens their time sheet).
 */
class MonthRankingWidget extends Widget
{
    use ReadsTimeClockFilters;

    protected string $view = 'timeclock.dashboard.ranking';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, array<int, array{employee: Employee, value: int}>>
     */
    public function rankings(): array
    {
        $reports = app(TimeClockReports::class);
        $people = $reports->month($this->month())['people'];

        $rankings = [
            'extra' => $reports->top($people, fn (array $row): int => $row['totals']['extra'] + $row['totals']['extra_rest']),
            'absences' => $reports->top($people, fn (array $row): int => $row['totals']['deficit']),
        ];

        // The largest balances, owed or to be taken (the sign shows which).
        if (collect($people)->contains(fn (array $row): bool => $row['bank'] !== null)) {
            $rankings['bank'] = collect($people)
                ->filter(fn (array $row): bool => (int) $row['bank'] !== 0)
                ->sortByDesc(fn (array $row): int => abs((int) $row['bank']))
                ->take(TimeClockReports::RANKING)
                ->map(fn (array $row): array => ['employee' => $row['employee'], 'value' => (int) $row['bank']])
                ->values()
                ->all();
        }

        return $rankings;
    }

    public function monthKey(): string
    {
        return $this->month()->format('Y-m');
    }
}
