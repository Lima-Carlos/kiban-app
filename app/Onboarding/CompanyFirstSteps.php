<?php

namespace App\Onboarding;

use App\Connections\ConnectionView;
use App\Filament\Pages\CompanyProfilePage;
use App\Filament\Pages\ConnectionsPage;
use App\Filament\Pages\DocumentsPage;
use App\Filament\Pages\PeoplePage;
use App\Filament\Pages\TeamsPage;
use App\Models\CompanyProfile;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Field\FieldModule;
use App\Modules\Fleet\FleetModule;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Modules\Step;
use App\Modules\TimeClock\TimeClockModule;
use App\People\Documents\PersonDocument;
use App\People\Person;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Company first steps": when the company has two or more systems, the start page shows ONE list that joins the
 * first steps of all of them, in the recommended order (the customer does not have to choose where to start):
 *
 * 1. Company and People (identity, people, teams, documents): the single register every system uses;
 * 2. Ponto (rules, workplaces and work schedules, used by the Campo/Agenda calendars);
 * 3. Frota (vehicles and drivers, which receive the km of the Campo);
 * 4. Campo and Agenda (they use people, schedules and vehicles);
 * and, to finish, "See how your systems help each other" (done when the "Integrations" page was opened).
 *
 * The steps of each system are the module's own Step definitions (Module::steps(), with their dependencies);
 * nothing is repeated here. With one system, the start page keeps the module's list as before.
 * Only for owners and administrators (who set the whole company up); a system manager keeps their own list.
 */
class CompanyFirstSteps
{
    /** Order of the systems in the combined list; others (e.g. the demonstration) go after them. */
    public const ORDER = [TimeClockModule::KEY, FleetModule::KEY, FieldModule::KEY, AgendaModule::KEY];

    /** Optional video at the top of the list: public/videos/por-onde-comecar-<lang>.mp4 ("Where to start"). */
    public const VIDEO = 'videos/por-onde-comecar';

    public function __construct(protected ModuleRegistry $modules) {}

    /** The combined list replaces the per-system lists for this person. */
    public function applies(Tenant $tenant, ?User $user): bool
    {
        return $user !== null && $user->canManageTenant() && count($this->modules->enabledFor($tenant)) >= 2;
    }

    /**
     * The groups of the list, in order, each with its steps' progress (Step::progress).
     *
     * @return array<int, array{key: string, title: string, icon: string, steps: array<int, array<string, mixed>>}>
     */
    public function groups(Tenant $tenant, User $user): array
    {
        $groups = [[
            'key' => 'company',
            'title' => __('app.first_steps.company'),
            'icon' => 'heroicon-o-building-office',
            'steps' => Step::progress($this->companySteps($tenant), $tenant),
        ]];

        foreach ($this->orderedModules($tenant) as $module) {
            if (! $module->showsSetupTo($user) || $module->steps() === []) {
                continue;
            }

            $groups[] = ['key' => $module->key(), 'title' => $module->name(), 'icon' => $module->icon(), 'steps' => $module->progress($tenant)];
        }

        $groups[] = [
            'key' => 'together',
            'title' => __('app.first_steps.together'),
            'icon' => 'heroicon-o-puzzle-piece',
            'steps' => Step::progress([$this->integrationsStep()], $tenant),
        ];

        return $groups;
    }

    /**
     * The whole list for the start page: groups, totals and the optional video.
     *
     * @return array{groups: array<int, array<string, mixed>>, done: int, total: int, percent: int}
     */
    public function summary(Tenant $tenant, User $user): array
    {
        $groups = $this->groups($tenant, $user);
        $steps = collect($groups)->flatMap(fn (array $group): array => $group['steps']);
        $done = $steps->where('done', true)->count();
        $total = $steps->count();

        return [
            'groups' => $groups,
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($done * 100 / $total) : 100,
        ];
    }

    /**
     * Company and People: what every system uses.
     *
     * @return array<int, Step>
     */
    public function companySteps(Tenant $tenant): array
    {
        return [
            new Step(
                key: 'identity',
                labelKey: 'app.first_steps.identity',
                descriptionKey: 'app.first_steps.identity_help',
                done: fn (): bool => CompanyProfile::query()->where(fn (Builder $query) => $query->whereNotNull('logo_path')->orWhereNotNull('legal_name'))->exists(),
                url: fn (): string => CompanyProfilePage::getUrl(),
            ),
            new Step(
                key: 'people',
                labelKey: 'app.first_steps.people',
                descriptionKey: 'app.first_steps.people_help',
                // Someone besides the owners (who sign up the company).
                done: fn (Tenant $tenant): bool => Person::query()
                    ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhereNotIn('user_id', $tenant->users()->wherePivot('role', User::ROLE_OWNER)->pluck('users.id')))
                    ->exists(),
                url: fn (): string => PeoplePage::getUrl(),
            ),
            new Step(
                key: 'teams',
                labelKey: 'app.first_steps.teams',
                descriptionKey: 'app.first_steps.teams_help',
                requires: ['people'],
                done: fn (): bool => Team::query()->exists(),
                url: fn (): string => TeamsPage::getUrl(),
            ),
            new Step(
                key: 'documents',
                labelKey: 'app.first_steps.documents',
                descriptionKey: 'app.first_steps.documents_help',
                requires: ['people'],
                done: fn (): bool => PersonDocument::query()->exists(),
                url: fn (): string => DocumentsPage::getUrl(),
            ),
        ];
    }

    /** "See how your systems help each other": done when someone of the company opened the "Integrations" page. */
    public function integrationsStep(): Step
    {
        return new Step(
            key: 'integrations',
            labelKey: 'app.first_steps.integrations',
            descriptionKey: 'app.first_steps.integrations_help',
            done: fn (): bool => ConnectionView::query()->exists(),
            url: fn (): string => ConnectionsPage::getUrl(),
        );
    }

    /**
     * The company's systems in the recommended order.
     *
     * @return array<int, Module>
     */
    protected function orderedModules(Tenant $tenant): array
    {
        $enabled = $this->modules->enabledFor($tenant);
        $rank = array_flip(self::ORDER);

        uksort($enabled, fn (string $a, string $b): int => ($rank[$a] ?? PHP_INT_MAX) <=> ($rank[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b));

        return array_values($enabled);
    }
}
