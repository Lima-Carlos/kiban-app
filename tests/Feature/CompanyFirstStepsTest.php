<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Filament\Pages\ConnectionsPage;
use App\Models\Team;
use App\Models\User;
use App\Onboarding\CompanyFirstSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Company first steps": with two or more systems, one list joins the first steps of all of them in the
 * recommended order (Company and People → Ponto → Frota → Campo and Agenda → Integrations); with one system, the
 * start page keeps the module's own list.
 */
class CompanyFirstStepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_system_keeps_its_own_list(): void
    {
        $tenant = $this->tenant('padaria', ['demo' => []]);
        $owner = $this->signIn($tenant);

        $this->assertFalse(app(CompanyFirstSteps::class)->applies($tenant, $owner));
        $this->get($tenant->url('/'))->assertOk()
            ->assertSee(__('demo.steps.list'))
            ->assertSee(__('app.home.progress', ['done' => 0, 'total' => 3]))
            ->assertDontSee(__('app.first_steps.title'))
            ->assertDontSee('data-steps-group', false);
    }

    public function test_several_systems_get_one_list_in_the_recommended_order(): void
    {
        $tenant = $this->tenant('aurora', ['campo' => [], 'frota' => [], 'ponto' => [], 'agenda' => []]);
        $owner = $this->signIn($tenant);
        $service = app(CompanyFirstSteps::class);

        $this->assertTrue($service->applies($tenant, $owner));
        $groups = $service->groups($tenant, $owner);
        $this->assertSame(['company', 'ponto', 'frota', 'campo', 'agenda', 'together'], array_column($groups, 'key'));
        $this->assertSame(['identity', 'people', 'teams', 'documents'], array_column($groups[0]['steps'], 'key'));
        $this->assertSame(['integrations'], array_column($groups[5]['steps'], 'key'));

        // The steps of each system are the module's own (with their dependencies): the Ponto's workplace waits for the rules.
        $clock = collect($groups[1]['steps'])->keyBy('key');
        $this->assertSame(['rules', 'workplace', 'schedule', 'employees'], $clock->keys()->all());
        $this->assertFalse($clock['workplace']['available']);
        $this->assertFalse(collect($groups[0]['steps'])->keyBy('key')['teams']['available']);

        // One list on the start page, whatever system is chosen at the top, and no separate list of the system.
        $total = collect($groups)->sum(fn (array $group): int => count($group['steps']));
        $this->get($tenant->url('/'))->assertOk()
            ->assertSee(__('app.first_steps.title'))
            ->assertSee(__('app.home.progress', ['done' => 0, 'total' => $total]))
            ->assertSeeInOrder([
                'data-steps-group="company"', __('app.first_steps.identity'),
                'data-steps-group="ponto"', __('timeclock.steps.rules'),
                'data-steps-group="frota"', __('fleet.steps.vehicle'),
                'data-steps-group="campo"', __('field.steps.business'),
                'data-steps-group="agenda"', __('agenda.steps.business'),
                'data-steps-group="together"', __('app.first_steps.integrations'),
            ], false)
            ->assertDontSee('data-company-steps-video', false);
    }

    public function test_company_steps_follow_the_real_data(): void
    {
        $tenant = $this->tenant('aurora', ['campo' => [], 'ponto' => []]);
        $owner = $this->signIn($tenant);
        $service = app(CompanyFirstSteps::class);
        $done = fn (): array => collect($service->groups($tenant, $owner))->flatMap(fn (array $group): array => $group['steps'])->where('done', true)->pluck('key')->values()->all();

        $this->assertSame([], $done());

        Team::create(['name' => 'Instalação']);
        $this->assertSame(['teams'], $done());

        // Opening the "Integrations" page finishes the last step.
        $this->get(ConnectionsPage::getUrl())->assertOk();
        $this->assertSame(['teams', 'integrations'], $done());
    }

    public function test_the_where_to_start_video_shows_when_recorded(): void
    {
        $tenant = $this->tenant('aurora', ['campo' => [], 'ponto' => []]);
        $this->signIn($tenant);
        $clip = public_path(CompanyFirstSteps::VIDEO.'-pt.mp4');
        @mkdir(dirname($clip), 0777, true);
        file_put_contents($clip, 'test');

        try {
            $this->get($tenant->url('/'))->assertOk()
                ->assertSee('data-company-steps-video', false)
                ->assertSee(__('app.first_steps.video'));
        } finally {
            @unlink($clip);
        }
    }

    public function test_a_system_manager_keeps_the_list_of_their_system(): void
    {
        $tenant = $this->tenant('aurora', ['campo' => [], 'ponto' => []]);
        $this->signIn($tenant);
        $manager = User::factory()->memberOf($tenant, 'member')->create(['name' => 'Rita RH']);
        $this->coreMember($tenant, $manager);
        app(AccessManager::class)->set($manager, 'ponto', 'manager');

        $this->assertFalse(app(CompanyFirstSteps::class)->applies($tenant, $manager));
    }

    public function test_every_text_in_the_three_languages(): void
    {
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            foreach (['title', 'help', 'video', 'company', 'together', 'identity', 'identity_help', 'people', 'people_help', 'teams', 'teams_help', 'documents', 'documents_help', 'integrations', 'integrations_help'] as $key) {
                $this->assertNotSame('app.first_steps.'.$key, __('app.first_steps.'.$key));
            }
        }
    }
}
