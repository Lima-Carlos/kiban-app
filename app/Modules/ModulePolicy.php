<?php

namespace App\Modules;

use App\Access\AccessManager;
use App\Licensing\License;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base rules for the screens of a module, applied by Filament and by any other entry point:
 *
 * - the company's license must include the module (otherwise the screens do not exist);
 * - "read-only" (contract ended / payment overdue): the data can be seen but not changed;
 * - creating can depend on first steps (e.g. no driver before a vehicle) and on the plan limit.
 *
 * Each denial carries a translated reason, shown to the person.
 */
abstract class ModulePolicy
{
    /** Module key (product code in the Core). */
    protected string $module;

    /** First steps that must be done before creating records here. */
    protected array $requiresSteps = [];

    /** License limit counted by this model (e.g. "vehicles"), if the plan limits it. */
    protected ?string $limit = null;

    /** @var class-string<Model>|null model counted for the limit */
    protected ?string $model = null;

    public function viewAny(User $user): Response
    {
        return $this->access();
    }

    public function view(User $user, Model $record): Response
    {
        return $this->viewAny($user);
    }

    public function create(User $user): Response
    {
        $write = $this->write();

        if ($write->denied()) {
            return $write;
        }

        $module = app(ModuleRegistry::class)->get($this->module);
        $tenant = app(TenantContext::class)->get();

        foreach ($this->requiresSteps as $step) {
            if (! $module->isStepDone($tenant, $step)) {
                $label = collect($module->steps())->firstWhere('key', $step)?->label() ?? $step;

                return Response::deny(__('app.denied.step', ['step' => $label]));
            }
        }

        if ($this->limit !== null && $this->model !== null) {
            $max = $this->license()->limitWithTolerance($this->module, $this->limit);

            if ($max !== null && $this->countForLimit() >= $max) {
                return Response::deny(__('app.denied.limit', ['limit' => $this->license()->limit($this->module, $this->limit)]));
            }
        }

        return Response::allow();
    }

    /**
     * The first step to do now before creating records here (a required step, or one it still waits for),
     * as in Module::progress(); null when nothing is missing. Used to point the empty list at that step.
     *
     * @return array<string, mixed>|null
     */
    public function pendingStep(): ?array
    {
        if ($this->requiresSteps === []) {
            return null;
        }

        $module = app(ModuleRegistry::class)->get($this->module);
        $progress = $module->progress(app(TenantContext::class)->get());
        $keys = array_column($progress, 'key');

        foreach ($this->requiresSteps as $required) {
            $position = array_search($required, $keys, true);

            if ($position === false || $progress[$position]['done']) {
                continue;
            }

            // The required step, or the first earlier step it is waiting for.
            foreach (array_slice($progress, 0, $position + 1) as $step) {
                if (! $step['done'] && $step['available']) {
                    return $step;
                }
            }

            return $progress[$position];
        }

        return null;
    }

    /** How many records count in the plan limit (override to leave some out, e.g. inactive vehicles). */
    protected function countForLimit(): int
    {
        return ($this->model)::query()->count();
    }

    public function update(User $user, Model $record): Response
    {
        return $this->write();
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->write();
    }

    public function deleteAny(User $user): Response
    {
        return $this->write();
    }

    protected function access(): Response
    {
        if (! $this->license()->hasModule($this->module)) {
            return Response::deny(__('app.denied.module'));
        }

        // The company has the module, but this person may not (e.g. HR does not see the fleet).
        return app(AccessManager::class)->hasAccess(auth()->user(), $this->module)
            ? Response::allow()
            : Response::deny(__('app.denied.no_access'));
    }

    protected function write(): Response
    {
        $access = $this->access();

        if ($access->denied()) {
            return $access;
        }

        return $this->license()->isReadOnly($this->module)
            ? Response::deny(__('app.denied.read_only'))
            : Response::allow();
    }

    protected function license(): License
    {
        return app(TenantContext::class)->get()->license();
    }
}
