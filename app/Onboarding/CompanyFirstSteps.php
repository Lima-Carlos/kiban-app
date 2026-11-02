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
 * 1. Company (identity and teams);
 * 2. the settings of each system, which the register of the employees needs: Ponto (rules, workplaces, work
 *    schedules), Frota (vehicles, stations), Campo and Agenda (business and services);
 * 3. Employees: the register itself (it asks for the schedule, the workplace and the vehicles, so it comes after
 *    them), then who clocks in, the drivers, the Agenda professionals and the documents;
 * and, to finish, "See how your systems help each other" (done when the "Integrations" page was opened).
 *
 * The steps of each system are the module's own Step definitions (Module::steps(), with their dependencies); the
 * ones about people (PEOPLE_STEPS) move to the "Employees" group. Every dependency is checked over the whole
 * list, so a step is only open when what it needs is done, wherever it is. With one system, the start page keeps
 * the module's list as before.
 * Only for owners and administrators (who set the whole company up); a system manager keeps their own list.
 */
class CompanyFirstSteps
{
    /** Order of the systems in the combined list; others (e.g. the demonstration) go after them. */
    public const ORDER = [TimeClockModule::KEY, FleetModule::KEY, FieldModule::KEY, AgendaModule::KEY];

    /** Steps of the systems that are done through the employees' register: they go to the "Employees" group. */
    public const PEOPLE_STEPS = [TimeClockModule::KEY.'.employees', FleetModule::KEY.'.driver', AgendaModule::KEY.'.professionals'];

    /** What the employees' register asks for, when the company has that system (system => steps). */
    public const PEOPLE_NEEDS = [TimeClockModule::KEY => ['workplace', 'schedule'], FleetModule::KEY => ['vehicle']];

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
        // [group key, title, icon] in order, and the steps of each group with keys unique in the whole list.
        $groups = ['company' => [__('app.first_steps.company'), 'heroicon-o-building-office', []]];
        $later = [];
        $needs = [];

        foreach ($this->companySteps($tenant) as $step) {
            $groups['company'][2][] = $this->scoped('company', $step);
        }

        foreach ($this->orderedModules($tenant) as $module) {
            if (! $module->showsSetupTo($user) || $module->steps() === []) {
                continue;
            }

            $groups[$module->key()] = [$module->name(), $module->icon(), []];

            foreach ($module->steps() as $step) {
                $scoped = $this->scoped($module->key(), $step);
                in_array($scoped->key, self::PEOPLE_STEPS, true) ? $later[] = $scoped : $groups[$module->key()][2][] = $scoped;
            }

            foreach (self::PEOPLE_NEEDS[$module->key()] ?? [] as $need) {
                $needs[] = $module->key().'.'.$need;
            }
        }

        $groups['people'] = [__('app.first_steps.people_group'), 'heroicon-o-users', [
            $this->scoped('people', $this->peopleStep($tenant), $needs),
            ...array_map(fn (Step $step): Step => $this->withRequirement($step, 'people.people'), $later),
            $this->scoped('people', $this->documentsStep(), ['people.people']),
        ]];
        $groups['together'] = [__('app.first_steps.together'), 'heroicon-o-puzzle-piece', [$this->scoped('together', $this->integrationsStep())]];

        // Progress over the whole list (a step may wait for a step of another group), then split again.
        $all = array_merge(...array_map(fn (array $group): array => $group[2], array_values($groups)));
        $progress = collect(Step::progress($all, $tenant))->keyBy('key');
        $result = [];

        foreach ($groups as $key => [$title, $icon, $steps]) {
            if ($steps === []) {
                continue;
            }

            $result[] = ['key' => $key, 'title' => $title, 'icon' => $icon, 'steps' => array_map(function (Step $step) use ($progress): array {
                $item = $progress[$step->key];
                $item['key'] = str_contains($step->key, '.') ? substr($step->key, strpos($step->key, '.') + 1) : $step->key;

                return $item;
            }, $steps)];
        }

        return $result;
    }

    /** The same step with a key unique in the whole list ("ponto.rules"); its requirements follow (plus extra ones). */
    protected function scoped(string $prefix, Step $step, array $extra = []): Step
    {
        return new Step(
            key: $prefix.'.'.$step->key,
            labelKey: $step->labelKey,
            done: $step->done,
            requires: [...array_map(fn (string $key): string => $prefix.'.'.$key, $step->requires), ...$extra],
            url: $step->url,
            descriptionKey: $step->descriptionKey,
        );
    }

    protected function withRequirement(Step $step, string $key): Step
    {
        return new Step(key: $step->key, labelKey: $step->labelKey, done: $step->done, requires: [...$step->requires, $key], url: $step->url, descriptionKey: $step->descriptionKey);
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
     * Company: identity and teams (teams no longer wait for the employees: the register can pick a team).
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
                key: 'teams',
                labelKey: 'app.first_steps.teams',
                descriptionKey: 'app.first_steps.teams_help',
                done: fn (): bool => Team::query()->exists(),
                url: fn (): string => TeamsPage::getUrl(),
            ),
        ];
    }

    /** "Register the employees": someone besides the owners (who signed up the company). */
    public function peopleStep(Tenant $tenant): Step
    {
        return new Step(
            key: 'people',
            labelKey: 'app.first_steps.people',
            descriptionKey: 'app.first_steps.people_help',
            done: fn (Tenant $tenant): bool => Person::query()
                ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhereNotIn('user_id', $tenant->users()->wherePivot('role', User::ROLE_OWNER)->pluck('users.id')))
                ->exists(),
            url: fn (): string => PeoplePage::getUrl(),
        );
    }

    public function documentsStep(): Step
    {
        return new Step(
            key: 'documents',
            labelKey: 'app.first_steps.documents',
            descriptionKey: 'app.first_steps.documents_help',
            done: fn (): bool => PersonDocument::query()->exists(),
            url: fn (): string => DocumentsPage::getUrl(),
        );
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
