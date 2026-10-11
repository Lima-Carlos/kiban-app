<?php

namespace Tests\Feature;

use App\Modules\Demo\DemoModule;
use App\Modules\Demo\Filament\Pages\ManageTaskLists;
use App\Modules\Demo\Filament\Pages\ManageTasks;
use App\Modules\Demo\Filament\TaskResource;
use App\Modules\Demo\Models\Task;
use App\Modules\Demo\Models\TaskList;
use App\Modules\OnboardingReporter;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * First-steps assistant: steps in order, a step opens only after the ones it depends on,
 * and the progress is sent to the Core.
 */
class FirstStepsTest extends TestCase
{
    use RefreshDatabase;

    protected function onboardingCalls(): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/onboarding'))->count();
    }

    public function test_steps_open_in_order(): void
    {
        $tenant = $this->tenant();
        $this->signIn($tenant);

        $steps = collect(app(DemoModule::class)->progress($tenant))->keyBy('key');
        $this->assertTrue($steps['list']['available']);
        $this->assertFalse($steps['task']['available']);
        $this->assertSame([__('demo.steps.list')], $steps['task']['missing']);

        // The home page shows the assistant; the task step is locked.
        $this->get($tenant->url('/'))->assertOk()
            ->assertSee(__('demo.steps.list'))
            ->assertSee(__('app.home.locked', ['steps' => __('demo.steps.list')]))
            ->assertSee(__('app.home.progress', ['done' => 0, 'total' => 3]));

        // No task before a list exists, and the screen says why.
        $this->assertFalse(TaskResource::canCreate());
        $this->get($tenant->url('/demo/tasks'))->assertOk()->assertSee(__('app.denied.step', ['step' => __('demo.steps.list')]));

        Livewire::test(ManageTaskLists::class)
            ->callAction(CreateAction::class, ['name' => 'Loja'])
            ->assertHasNoActionErrors();

        $this->assertTrue(TaskResource::canCreate());

        $user = auth()->user();
        Livewire::test(ManageTasks::class)
            ->callAction(CreateAction::class, ['task_list_id' => TaskList::sole()->id, 'title' => 'Abrir a loja', 'completed' => true])
            ->assertHasNoActionErrors();

        $task = Task::sole();
        $this->assertSame($user->id, $task->created_by);
        $this->assertNotNull($task->completed_at);
        $this->assertSame($tenant->id, $task->tenant_id);

        $this->get($tenant->url('/'))->assertOk()->assertSee(__('app.home.all_done'));
    }

    public function test_progress_is_sent_only_when_it_changes(): void
    {
        $tenant = $this->tenant();
        $this->useTenant($tenant);
        $reporter = app(OnboardingReporter::class);

        $this->assertSame(1, $reporter->report($tenant));
        $this->assertSame(0, $reporter->report($tenant->fresh()));
        $this->assertSame(1, $this->onboardingCalls());

        TaskList::create(['name' => 'Loja']);
        $this->assertSame(1, $reporter->report($tenant->fresh()));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/onboarding')
            && $request['steps'][0] === ['key' => 'list', 'done' => true]);

        // Core down: nothing is lost, it is sent next time.
        TaskList::create(['name' => 'Escritório']);
        Task::create(['task_list_id' => TaskList::first()->id, 'title' => 'Teste']);
        $this->coreDown = true;
        $this->assertSame(0, $reporter->report($tenant->fresh()));
        $this->coreDown = false;
        $this->assertSame(1, $reporter->report($tenant->fresh()));
    }

    public function test_a_change_on_screen_reports_after_the_answer(): void
    {
        $tenant = $this->tenant();
        $this->signIn($tenant);

        Livewire::test(ManageTaskLists::class)->callAction(CreateAction::class, ['name' => 'Loja']);
        $this->assertSame(0, $this->onboardingCalls());

        // Deferred until the page was answered.
        app(DeferredCallbackCollection::class)->invoke();
        $this->assertSame(1, $this->onboardingCalls());
    }

    public function test_only_managers_delete_lists(): void
    {
        $tenant = $this->tenant();
        $this->signIn($tenant, 'member');
        $list = TaskList::create(['name' => 'Loja']);

        Livewire::test(ManageTaskLists::class)
            ->assertTableActionVisible('edit', $list)
            ->assertTableActionHidden('delete', $list);

        $this->signIn($tenant, 'admin');
        Livewire::test(ManageTaskLists::class)->assertTableActionVisible('delete', $list);
    }
}
