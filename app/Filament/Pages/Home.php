<?php

namespace App\Filament\Pages;

use App\Access\CurrentModule;
use App\Connections\ConnectionCatalog;
use App\Models\Tenant;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Onboarding\CompanyFirstSteps;
use App\People\Documents\PersonDocumentService;
use App\People\Documents\TrainingService;
use App\People\Epi\EpiService;
use App\Support\LocalVideo;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Start page: the company's modules and their first-steps assistant (what to do, in which order).
 */
class Home extends Page
{
    protected static ?int $navigationSort = -10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected string $view = 'filament.pages.home';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function getNavigationLabel(): string
    {
        return __('app.home.title');
    }

    public function getTitle(): string|Htmlable
    {
        // "Welcome, Maria" (the person's first name).
        return __('app.home.welcome', ['name' => (string) str((string) auth()->user()?->name)->trim()->before(' ')]);
    }

    /**
     * @return array<int, array{module: Module, steps: array<int, array<string, mixed>>, done: int, total: int, percent: int}>
     */
    public function getModules(): array
    {
        $tenant = $this->tenant();
        $user = auth()->user();

        // With two or more systems, owners and administrators get one combined list (getCompanySteps()) instead.
        $combined = app(CompanyFirstSteps::class)->applies($tenant, $user);

        // Only the system chosen at the top of the menu (one system, many modules).
        return collect(array_filter([app(CurrentModule::class)->get()]))
            ->map(function (Module $module) use ($tenant, $user, $combined): array {
                // First steps only for who sets the module up; the others get their own block (e.g. driver buttons).
                $steps = ! $combined && $module->showsSetupTo($user) ? $module->progress($tenant) : [];
                $done = count(array_filter($steps, fn (array $step): bool => $step['done']));
                $total = count($steps);

                return [
                    'module' => $module,
                    'personal' => $module->personalHome($user),
                    'widgets' => $module->homeWidgets($user),
                    'steps' => $steps,
                    'done' => $done,
                    'total' => $total,
                    'percent' => $total > 0 ? (int) round($done * 100 / $total) : 100,
                    'read_only' => $tenant->license()->isReadOnly($module->key()),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "Company first steps": one list with the steps of every system, in the recommended order (Company and
     * People → Ponto → Frota → Campo and Agenda → Integrations), when the company has two or more systems and the
     * person sets the company up; null otherwise (each system keeps its own list).
     *
     * @return array{groups: array<int, array<string, mixed>>, done: int, total: int, percent: int, video: ?array{src: string, poster: ?string}}|null
     */
    public function getCompanySteps(): ?array
    {
        $tenant = $this->tenant();
        $user = auth()->user();
        $service = app(CompanyFirstSteps::class);

        if (! $service->applies($tenant, $user)) {
            return null;
        }

        return $service->summary($tenant, $user) + ['video' => LocalVideo::find(CompanyFirstSteps::VIDEO)];
    }

    /**
     * Urgent items of every module this person can open.
     *
     * @return array<int, array{text: string, url: ?string, color: string}>
     */
    public function getAttention(): array
    {
        return collect(array_filter([app(CurrentModule::class)->get()]))
            ->flatMap(fn (Module $module): array => $module->attention(auth()->user()))
            // Common to every system: the people's documents expired or expiring (who takes care of them).
            ->merge(app(PersonDocumentService::class)->attention(auth()->user()))
            // People missing a required course (NR-10, NR-35...).
            ->merge(app(TrainingService::class)->attention(auth()->user()))
            // Common to every system: PPE to replace, CA expired, signatures waiting, low stock; my deliveries to sign.
            ->merge(app(EpiService::class)->attention(auth()->user()))
            ->values()
            ->all();
    }

    /**
     * "There is news in the integrations of your systems. See": only for who has "Integrations" in the menu
     * (owner, administrators, managers), while something there is unseen and the note was not closed for these
     * same connections (a new one brings it back).
     */
    public function showsIntegrationsNote(): bool
    {
        $user = auth()->user();

        if ($user === null || ! ConnectionsPage::canAccess() || ! ConnectionsPage::shouldRegisterNavigation()) {
            return false;
        }

        $unseen = array_keys(app(ConnectionCatalog::class)->unseen($user));

        return $unseen !== [] && array_diff($unseen, session($this->integrationsNoteKey(), [])) !== [];
    }

    /** "Close" on the note: hidden for the connections unseen now (session only; the menu counter stays). */
    public function hideIntegrationsNote(): void
    {
        session()->put($this->integrationsNoteKey(), array_keys(app(ConnectionCatalog::class)->unseen(auth()->user())));
    }

    protected function integrationsNoteKey(): string
    {
        return 'kiban.integrations_note.'.$this->tenant()->getKey();
    }

    public function companyHasModules(): bool
    {
        return app(ModuleRegistry::class)->enabledFor($this->tenant()) !== [];
    }

    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->get();
    }
}
