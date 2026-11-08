<?php

namespace App\Modules\Rotina\Services;

use App\Models\User;
use App\Modules\Rotina\Models\Entry;
use App\Modules\Rotina\Models\Rate;
use App\Modules\Rotina\Models\Task;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\Services\TimesheetDocument;
use App\Modules\TimeClock\TimeClockModule;
use App\Support\Money;
use App\Support\Spreadsheet;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Hours of the month for the manager: per project (and client) and per person, plus the tasks done. With the
 * value of the hour of each person, the cost of each project; with a price per hour for the client, the revenue
 * and the margin.
 */
class RotinaReports
{
    /**
     * @return array{projects: array<int, array{name: string, client: ?string, minutes: int, cost: int, revenue: ?int, margin: ?int}>, people: array<int, array{name: string, minutes: int, tasks_done: int, clocked: ?int}>, total: int, with_ponto: bool, with_costs: bool, with_revenue: bool, cost: int, revenue: int}
     */
    public function month(CarbonImmutable $month): array
    {
        $entries = Entry::query()->with(['project', 'user'])->whereNull('started_at')
            ->whereBetween('worked_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])->get();

        $rates = Rate::query()->pluck('hour_cents', 'user_id');
        $cost = fn ($group): int => (int) round($group->sum(fn (Entry $entry): float => $entry->minutes * (int) ($rates[$entry->user_id] ?? 0) / 60));

        $projects = $entries->groupBy(fn (Entry $entry): int => (int) $entry->project_id)->map(function ($group) use ($cost): array {
            $project = $group->first()->project;
            $minutes = (int) $group->sum('minutes');
            $revenue = $project?->billable && $project->hour_price_cents ? (int) round($minutes * $project->hour_price_cents / 60) : null;

            return [
                'name' => $project?->name ?? __('rotina.reports.no_project'),
                'client' => $project?->client_name,
                'minutes' => $minutes,
                'cost' => $cost($group),
                'revenue' => $revenue,
                'margin' => $revenue === null ? null : $revenue - $cost($group),
            ];
        })->sortByDesc('minutes')->values()->all();

        $done = Task::query()->whereBetween('done_at', [$month->startOfMonth(), $month->endOfMonth()])->get()->groupBy('user_id')->map->count();

        $withPonto = $this->hasPonto();

        $people = $entries->groupBy('user_id')->map(fn ($group, $userId): array => [
            'name' => (string) $group->first()->user?->name,
            'minutes' => (int) $group->sum('minutes'),
            'tasks_done' => (int) ($done[$userId] ?? 0),
            // With the Ponto: the hours clocked in the month, to compare with the hours on projects.
            'clocked' => $withPonto && $group->first()->user ? $this->clocked($group->first()->user, $month) : null,
        ])->sortByDesc('minutes')->values()->all();

        return [
            'projects' => $projects, 'people' => $people, 'total' => (int) $entries->sum('minutes'), 'with_ponto' => $withPonto,
            // Money columns only when the company filled in the values.
            'with_costs' => $rates->isNotEmpty(),
            'with_revenue' => collect($projects)->contains(fn (array $row): bool => $row['revenue'] !== null),
            'cost' => (int) collect($projects)->sum('cost'),
            'revenue' => (int) collect($projects)->sum('revenue'),
        ];
    }

    /** The company also has the Ponto (Kiban Core Ponto): the summary shows the hours clocked. */
    public function hasPonto(): bool
    {
        return (bool) app(TenantContext::class)->current()?->license()->hasModule(TimeClockModule::KEY);
    }

    /** Minutes the person clocked in the month (null when the person does not clock in). */
    public function clocked(User $user, CarbonImmutable $month): ?int
    {
        $employee = app(TimeClockAccess::class)->employeeFor($user);

        return $employee === null ? null : (int) (app(TimesheetDocument::class)->month($employee, $month->startOfMonth())['totals']['worked'] ?? 0);
    }

    /** Share of the clocked hours that went to projects ("72%"), when both exist. */
    public static function share(int $minutes, ?int $clocked): ?int
    {
        return $clocked ? (int) round($minutes * 100 / $clocked) : null;
    }

    public static function money(?int $cents): string
    {
        return Money::format($cents);
    }

    /**
     * Sets the value of the hour of each person (empty removes it).
     *
     * @param  array<int|string, mixed>  $values  user id => value in money (e.g. "45.50")
     */
    public function setRates(array $values): void
    {
        foreach ($values as $userId => $value) {
            if (blank($value)) {
                Rate::query()->where('user_id', $userId)->delete();

                continue;
            }

            Rate::query()->updateOrCreate(['user_id' => (int) $userId], ['hour_cents' => (int) round((float) $value * 100)]);
        }
    }

    public function export(CarbonImmutable $month): string
    {
        $data = $this->month($month);
        $hours = fn (int $minutes): float => round($minutes / 60, 2);

        $money = fn (?int $cents): ?float => $cents === null ? null : round($cents / 100, 2);
        $projects = [[__('rotina.fields.project'), __('rotina.fields.client'), __('rotina.reports.hours'), ...($data['with_costs'] ? [__('rotina.reports.cost')] : []), ...($data['with_revenue'] ? [__('rotina.reports.revenue'), __('rotina.reports.margin')] : [])]];
        foreach ($data['projects'] as $row) {
            $projects[] = [$row['name'], $row['client'], $hours($row['minutes']), ...($data['with_costs'] ? [$money($row['cost'])] : []), ...($data['with_revenue'] ? [$money($row['revenue']), $money($row['margin'])] : [])];
        }
        $projects[] = ['__bold__', __('rotina.reports.total'), '', $hours($data['total'])];

        $people = [[__('rotina.fields.person'), __('rotina.reports.hours'), __('rotina.reports.tasks_done'), ...($data['with_ponto'] ? [__('rotina.reports.clocked'), __('rotina.reports.share')] : [])]];
        foreach ($data['people'] as $row) {
            $people[] = [$row['name'], $hours($row['minutes']), $row['tasks_done'], ...($data['with_ponto'] ? [$row['clocked'] === null ? null : $hours($row['clocked']), self::share($row['minutes'], $row['clocked'])] : [])];
        }

        return Spreadsheet::write(__('rotina.reports.file', ['month' => $month->format('Y-m')]), [
            __('rotina.reports.by_project') => $projects,
            __('rotina.reports.by_person') => $people,
        ]);
    }
}
