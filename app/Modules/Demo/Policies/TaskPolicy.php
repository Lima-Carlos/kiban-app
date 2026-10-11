<?php

namespace App\Modules\Demo\Policies;

use App\Modules\Demo\Models\Task;
use App\Modules\ModulePolicy;

class TaskPolicy extends ModulePolicy
{
    protected string $module = 'demo';

    /** A task needs a list: the "list" first step must be done. */
    protected array $requiresSteps = ['list'];

    /** Plan limit "tasks" (if the plan in the Core defines it). */
    protected ?string $limit = 'tasks';

    protected ?string $model = Task::class;
}
