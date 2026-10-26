<?php

namespace Tests\Feature;

use App\Modules\Demo\Filament\Pages\ManageTaskLists;
use App\Modules\Demo\Models\TaskList;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\LivewireManager;
use Tests\TestCase;

/**
 * Like a browser: the screen is opened on the company address and the buttons send their background
 * requests (Livewire) to the server. These requests must also know the company, the license and the person.
 */
class BrowserFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Snapshot of a component as the browser receives it in the page.
     */
    protected function snapshotFrom(string $html, string $component): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
                return $snapshot;
            }
        }

        $this->fail("Component [{$component}] not found on the page.");
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  array<int, array{method: string, params: array<int, mixed>}>  $calls
     */
    protected function livewire(string $host, string $snapshot, array $updates, array $calls): TestResponse
    {
        // A real request starts with nothing in memory: the company must come from the address again.
        app(TenantContext::class)->forget();
        app(LivewireManager::class)->flushState();

        return $this->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => array_map(fn (array $call): array => $call + ['path' => ''], $calls),
            ]],
        ]);
    }

    public function test_creating_a_record_through_the_screen_buttons(): void
    {
        $tenant = $this->tenant();
        $this->signIn($tenant);
        $host = rtrim($tenant->url('/'), '/');

        $html = $this->get($tenant->url('/demo/lists'))->assertOk()->getContent();
        $snapshot = $this->snapshotFrom($html, ManageTaskLists::class);

        // Click "Create": opens the form.
        $opened = $this->livewire($host, $snapshot, [], [['method' => 'mountAction', 'params' => ['create']]])->assertOk();
        $snapshot = $opened->json('components.0.snapshot');

        // Fill in and save.
        $this->livewire($host, $snapshot, ['mountedActions.0.data.name' => 'Loja'], [['method' => 'callMountedAction', 'params' => []]])
            ->assertOk();

        $this->useTenant($tenant);
        $this->assertSame(['Loja'], TaskList::pluck('name')->all());
    }
}
