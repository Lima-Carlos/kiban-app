<?php

namespace Tests\Feature;

use App\Modules\Demo\Filament\Pages\ManageTasks;
use App\Modules\Demo\Models\Task;
use App\Modules\Demo\Models\TaskList;
use App\Tenancy\MissingTenantException;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One company can never see or change another company's data.
 */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_company_only_sees_its_own_data(): void
    {
        $padaria = $this->tenant('padaria');
        $oficina = $this->tenant('oficina');
        $context = app(TenantContext::class);

        $breadList = $context->run($padaria, fn () => TaskList::create(['name' => 'Pães']));
        $context->run($oficina, fn () => TaskList::create(['name' => 'Carros']));

        $this->assertSame($padaria->id, $breadList->tenant_id);

        $context->run($oficina, function () use ($breadList): void {
            $this->assertSame(['Carros'], TaskList::pluck('name')->all());
            $this->assertNull(TaskList::find($breadList->id));
            $this->assertSame(0, TaskList::whereKey($breadList->id)->count());
        });

        $context->run($padaria, fn () => $this->assertSame(['Pães'], TaskList::pluck('name')->all()));
    }

    public function test_no_company_selected_is_an_error_not_a_guess(): void
    {
        $this->tenant();

        $this->assertThrows(fn () => TaskList::count(), MissingTenantException::class);
        $this->assertThrows(fn () => TaskList::create(['name' => 'Sem empresa']), MissingTenantException::class);
    }

    public function test_records_cannot_move_or_be_saved_for_another_company(): void
    {
        $padaria = $this->tenant('padaria');
        $oficina = $this->tenant('oficina');
        $context = app(TenantContext::class);

        $list = $context->run($padaria, fn () => TaskList::create(['name' => 'Pães']));

        // Forcing another company on create or update is refused.
        $this->assertThrows(
            fn () => $context->run($oficina, fn () => (new TaskList(['name' => 'Intrusa']))->forceFill(['tenant_id' => $padaria->id])->save()),
            MissingTenantException::class,
        );
        $this->assertThrows(
            fn () => $context->run($padaria, fn () => $list->forceFill(['tenant_id' => $oficina->id])->save()),
            MissingTenantException::class,
        );

        // A record loaded under one company cannot be changed while another company is active.
        $this->assertThrows(fn () => $context->run($oficina, fn () => $list->update(['name' => 'Mudou'])), MissingTenantException::class);
        $this->assertThrows(fn () => $context->run($oficina, fn () => $list->delete()), MissingTenantException::class);

        // The database itself refuses a task of one company pointing to a list of another one.
        $this->expectException(QueryException::class);
        DB::table('tasks')->insert(['tenant_id' => $oficina->id, 'task_list_id' => $list->id, 'title' => 'Furou', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_screens_show_only_the_company_data(): void
    {
        $padaria = $this->tenant('padaria');
        $oficina = $this->tenant('oficina');
        $context = app(TenantContext::class);

        $otherTask = $context->run($oficina, function () {
            $list = TaskList::create(['name' => 'Carros']);

            return Task::create(['task_list_id' => $list->id, 'title' => 'Trocar óleo']);
        });

        $this->signIn($padaria);
        $list = TaskList::create(['name' => 'Pães']);
        $mine = Task::create(['task_list_id' => $list->id, 'title' => 'Assar pão']);

        Livewire::test(ManageTasks::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$otherTask]);
    }

    public function test_a_person_of_one_company_cannot_open_another(): void
    {
        $padaria = $this->tenant('padaria');
        $oficina = $this->tenant('oficina');

        $this->signIn($padaria);

        $this->get($padaria->url('/'))->assertOk();
        $this->get($oficina->url('/'))->assertForbidden()->assertSee(__('app.no_access.title'));
    }
}
