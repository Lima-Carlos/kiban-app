<?php

namespace App\Modules\Rotina\Services;

use App\Modules\Rotina\Models\Entry;
use App\Modules\Rotina\Models\Task;
use App\Support\Spreadsheet;
use Carbon\CarbonImmutable;

/**
 * Hours of the month for the manager: per project (and client) and per person, plus the tasks done.
 */
class RotinaReports
{
    /**
     * @return array{projects: array<int, array{name: string, client: ?string, minutes: int}>, people: array<int, array{name: string, minutes: int, tasks_done: int}>, total: int}
     */
    public function month(CarbonImmutable $month): array
    {
        $entries = Entry::query()->with(['project', 'user'])->whereNull('started_at')
            ->whereBetween('worked_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])->get();

        $projects = $entries->groupBy(fn (Entry $entry): int => (int) $entry->project_id)->map(fn ($group): array => [
            'name' => $group->first()->project?->name ?? __('rotina.reports.no_project'),
            'client' => $group->first()->project?->client_name,
            'minutes' => (int) $group->sum('minutes'),
        ])->sortByDesc('minutes')->values()->all();

        $done = Task::query()->whereBetween('done_at', [$month->startOfMonth(), $month->endOfMonth()])->get()->groupBy('user_id')->map->count();

        $people = $entries->groupBy('user_id')->map(fn ($group, $userId): array => [
            'name' => (string) $group->first()->user?->name,
            'minutes' => (int) $group->sum('minutes'),
            'tasks_done' => (int) ($done[$userId] ?? 0),
        ])->sortByDesc('minutes')->values()->all();

        return ['projects' => $projects, 'people' => $people, 'total' => (int) $entries->sum('minutes')];
    }

    public function export(CarbonImmutable $month): string
    {
        $data = $this->month($month);
        $hours = fn (int $minutes): float => round($minutes / 60, 2);

        $projects = [[__('rotina.fields.project'), __('rotina.fields.client'), __('rotina.reports.hours')]];
        foreach ($data['projects'] as $row) {
            $projects[] = [$row['name'], $row['client'], $hours($row['minutes'])];
        }
        $projects[] = ['__bold__', __('rotina.reports.total'), '', $hours($data['total'])];

        $people = [[__('rotina.fields.person'), __('rotina.reports.hours'), __('rotina.reports.tasks_done')]];
        foreach ($data['people'] as $row) {
            $people[] = [$row['name'], $hours($row['minutes']), $row['tasks_done']];
        }

        return Spreadsheet::write(__('rotina.reports.file', ['month' => $month->format('Y-m')]), [
            __('rotina.reports.by_project') => $projects,
            __('rotina.reports.by_person') => $people,
        ]);
    }
}
