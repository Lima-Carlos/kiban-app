<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Rotina\Filament\Entries\Pages\ManageEntries;
use App\Modules\Rotina\Filament\Projects\Pages\ManageProjects;
use App\Modules\Rotina\Filament\SummaryPage;
use App\Modules\Rotina\Filament\Tasks\Pages\ManageTasks;
use App\Modules\Rotina\Models\Entry;
use App\Modules\Rotina\Models\Project;
use App\Modules\Rotina\Models\Task;
use App\Modules\Rotina\Services\RotinaReports;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core Rotina: tasks and hours per project. Each person sees only their own; the manager sees all.
 */
class RotinaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_keeps_projects_tasks_and_hours_and_the_stopwatch_counts_minutes(): void
    {
        $tenant = $this->tenant('agencia', ['rotina' => []]);
        $owner = $this->signIn($tenant);

        Livewire::test(ManageProjects::class)
            ->callAction('create', ['name' => 'Site novo', 'client_name' => 'Padaria Sol'])
            ->assertHasNoActionErrors();
        $project = Project::query()->firstOrFail();
        $this->assertSame('Padaria Sol · Site novo', $project->label());

        Livewire::test(ManageTasks::class)
            ->callAction('create', ['title' => 'Montar o layout', 'project_id' => $project->getKey(), 'due_on' => now()->toDateString()])
            ->assertHasNoActionErrors();
        $this->assertSame($owner->getKey(), Task::query()->firstOrFail()->user_id);

        Livewire::test(ManageEntries::class)
            ->callAction('create', ['worked_on' => now()->toDateString(), 'project_id' => $project->getKey(), 'hours' => 2, 'minutes_part' => 30, 'note' => 'Layout'])
            ->assertHasNoActionErrors();
        $this->assertSame(150, Entry::query()->firstOrFail()->minutes);
        $this->assertSame('2 h 30 min', Entry::format(150));

        // Stopwatch: start, 45 minutes later stop.
        Livewire::test(ManageEntries::class)->callAction('start', ['project_id' => $project->getKey()]);
        $running = Entry::query()->running()->firstOrFail();
        $this->travel(45)->minutes();
        Livewire::test(ManageEntries::class)->callAction('stop');
        $this->assertSame(45, $running->fresh()->minutes);
        $this->assertNull($running->fresh()->started_at);

        $report = app(RotinaReports::class)->month(CarbonImmutable::now());
        $this->assertSame(195, $report['total']);
        $this->assertSame('Site novo', $report['projects'][0]['name']);
        Livewire::test(SummaryPage::class)->assertSee('Padaria Sol')->assertOk();
    }

    public function test_a_member_sees_only_their_own_and_cannot_change_projects(): void
    {
        $tenant = $this->tenant('agencia', ['rotina' => []]);
        $owner = $this->signIn($tenant);
        $project = Project::query()->create(['name' => 'Projeto A']);
        Task::query()->create(['user_id' => $owner->getKey(), 'title' => 'Tarefa do dono']);
        Entry::query()->create(['user_id' => $owner->getKey(), 'project_id' => $project->getKey(), 'worked_on' => now()->toDateString(), 'minutes' => 60]);

        $member = User::factory()->memberOf($tenant, 'member')->create();
        $this->coreMember($tenant, $member);
        $this->actingAs($member);
        $mine = Task::query()->create(['user_id' => $member->getKey(), 'title' => 'Tarefa da Rita']);

        Livewire::test(ManageTasks::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords(Task::query()->where('user_id', $owner->getKey())->get());
        Livewire::test(ManageEntries::class)->assertCountTableRecords(0);
        $this->assertFalse(SummaryPage::canAccess());
        $this->assertTrue($member->can('create', Task::class));
        $this->assertFalse($member->can('create', Project::class));
        $this->assertFalse($member->can('update', Task::query()->where('user_id', $owner->getKey())->first()));
    }

    public function test_companies_never_see_each_others_rotina(): void
    {
        $a = $this->tenant('alfa', ['rotina' => []]);
        $b = $this->tenant('beta', ['rotina' => []]);
        $context = app(TenantContext::class);
        $context->run($a, fn () => Project::query()->create(['name' => 'Segredo']));
        $context->run($b, fn () => $this->assertSame(0, Project::query()->count()));
    }

    public function test_every_text_in_the_three_languages(): void
    {
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            foreach (['module', 'steps.project', 'tasks.empty', 'projects.empty', 'entries.running', 'fields.hours', 'actions.start', 'reports.title', 'reports.not_timeclock'] as $key) {
                $this->assertNotSame('rotina.'.$key, __('rotina.'.$key));
            }
        }
    }
}
