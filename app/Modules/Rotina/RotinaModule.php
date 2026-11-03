<?php

namespace App\Modules\Rotina;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Module;
use App\Modules\Rotina\Filament\Entries\EntryResource;
use App\Modules\Rotina\Filament\Projects\ProjectResource;
use App\Modules\Rotina\Filament\SummaryPage;
use App\Modules\Rotina\Filament\Tasks\TaskResource;
use App\Modules\Rotina\Models\Entry;
use App\Modules\Rotina\Models\Project;
use App\Modules\Rotina\Models\Task;
use App\Modules\Rotina\Policies\ProjectPolicy;
use App\Modules\Rotina\Policies\RotinaPolicy;
use App\Modules\Rotina\Services\RotinaAccess;
use App\Modules\Step;

/**
 * Kiban Core Rotina ("Daily Log" in English): the day's tasks and the hours per client/project, for small teams
 * (office, home office, field). It is NOT a work-hours control: that is the Ponto. Product code in the Core:
 * "rotina". Everyone of the company can use it (their own tasks and hours); the manager keeps the projects and
 * sees the summary of the month.
 */
class RotinaModule extends Module
{
    public const KEY = 'rotina';

    public const MANAGER = 'manager';

    public const MEMBER = 'member';

    /** Plan limit in the Core: people using the Rotina (with tasks or hours). */
    public const LIMIT_PEOPLE = 'people';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('rotina.module');
    }

    public function icon(): string
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'project',
                labelKey: 'rotina.steps.project',
                descriptionKey: 'rotina.steps.project_help',
                done: fn (Tenant $tenant): bool => Project::query()->exists(),
                url: fn (): string => ProjectResource::getUrl('index'),
            ),
            new Step(
                key: 'task',
                labelKey: 'rotina.steps.task',
                descriptionKey: 'rotina.steps.task_help',
                done: fn (Tenant $tenant): bool => Task::query()->exists(),
                url: fn (): string => TaskResource::getUrl('index'),
            ),
            new Step(
                key: 'hours',
                labelKey: 'rotina.steps.hours',
                descriptionKey: 'rotina.steps.hours_help',
                requires: ['project'],
                done: fn (Tenant $tenant): bool => Entry::query()->exists(),
                url: fn (): string => EntryResource::getUrl('index'),
            ),
        ];
    }

    public function resources(): array
    {
        return [TaskResource::class, EntryResource::class, ProjectResource::class];
    }

    public function pages(): array
    {
        return [SummaryPage::class];
    }

    public function showsSetupTo(User $user): bool
    {
        return app(RotinaAccess::class)->isManager($user);
    }

    public function roles(): array
    {
        return [self::MANAGER => __('rotina.roles.manager'), self::MEMBER => __('rotina.roles.member')];
    }

    /** Everyone of the company keeps their own tasks and hours. */
    public function implicitRole(User $user): ?string
    {
        return self::MEMBER;
    }

    public function policies(): array
    {
        return [
            Project::class => ProjectPolicy::class,
            Task::class => RotinaPolicy::class,
            Entry::class => RotinaPolicy::class,
        ];
    }
}
