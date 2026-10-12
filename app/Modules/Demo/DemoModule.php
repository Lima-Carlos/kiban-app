<?php

namespace App\Modules\Demo;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Demo\Filament\TaskListResource;
use App\Modules\Demo\Filament\TaskResource;
use App\Modules\Demo\Models\Task;
use App\Modules\Demo\Models\TaskList;
use App\Modules\Demo\Policies\TaskListPolicy;
use App\Modules\Demo\Policies\TaskPolicy;
use App\Modules\Module;
use App\Modules\Step;

/**
 * Demonstration module (task lists). It is the model for real modules: a first step that unlocks the next,
 * a plan limit ("tasks") and the read-only mode. Product code in the Core: "demo".
 */
class DemoModule extends Module
{
    public function key(): string
    {
        return 'demo';
    }

    public function name(): string
    {
        return __('demo.module');
    }

    /** Demonstration: everyone of the company can use it. */
    public function implicitRole(User $user): ?string
    {
        return 'member';
    }

    public function icon(): string
    {
        return 'heroicon-o-check-circle';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'list',
                labelKey: 'demo.steps.list',
                descriptionKey: 'demo.steps.list_help',
                done: fn (Tenant $tenant): bool => TaskList::query()->exists(),
                url: fn (): string => TaskListResource::getUrl('index'),
            ),
            new Step(
                key: 'task',
                labelKey: 'demo.steps.task',
                descriptionKey: 'demo.steps.task_help',
                requires: ['list'],
                done: fn (Tenant $tenant): bool => Task::query()->exists(),
                url: fn (): string => TaskResource::getUrl('index'),
            ),
            new Step(
                key: 'complete',
                labelKey: 'demo.steps.complete',
                descriptionKey: 'demo.steps.complete_help',
                requires: ['task'],
                done: fn (Tenant $tenant): bool => Task::query()->completed()->exists(),
                url: fn (): string => TaskResource::getUrl('index'),
            ),
        ];
    }

    /**
     * @return array<int, class-string>
     */
    public function resources(): array
    {
        return [TaskListResource::class, TaskResource::class];
    }

    /**
     * @return array<class-string, class-string>
     */
    public function policies(): array
    {
        return [TaskList::class => TaskListPolicy::class, Task::class => TaskPolicy::class];
    }
}
