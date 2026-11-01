<?php

namespace App\Filament\Pages;

use App\Connections\Connection;
use App\Connections\ConnectionCatalog;
use App\Modules\Module;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "Integrations" (Company group): what the company's systems do together now and, for the owner and the
 * administrators, what another system would add ("With the Ponto, you would also have: ...", with the link to the
 * plans in the Kiban account). Helps the customer use (and buy) the second system without pushing.
 * The menu shows how many connections the person has not seen yet; opening the page marks them as seen.
 * The old address (empresa/sistemas-juntos) redirects here (routes/web.php).
 */
class ConnectionsPage extends Page
{
    /** The page's address before it was renamed "Integrations" (kept as a redirect). */
    public const OLD_SLUG = 'empresa/sistemas-juntos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $slug = 'empresa/integracoes';

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.pages.connections';

    public static function getNavigationGroup(): ?string
    {
        return __('app.menu.company');
    }

    /** Owners, administrators and who manages people (the "Company" group), plus anyone with a connection that matters to them. */
    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();
        $user = auth()->user();

        if ($tenant === null || $user === null || ! $tenant->license()->isActive()) {
            return false;
        }

        return PeoplePage::canAccess() || app(ConnectionCatalog::class)->relevant($user) !== [];
    }

    /** In the menu only for the "Company" group's people (technicians and drivers reach it from the window). */
    public static function shouldRegisterNavigation(): bool
    {
        return PeoplePage::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('connections.page.title');
    }

    /** How many connections the person has not seen yet; nothing once they open the page. */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $count = $user !== null ? count(app(ConnectionCatalog::class)->unseen($user)) : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'success';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('connections.badge');
    }

    public function mount(): void
    {
        app(ConnectionCatalog::class)->markSeen(auth()->user());
    }

    public function getTitle(): string
    {
        return __('connections.page.title');
    }

    public function getSubheading(): ?string
    {
        return __('connections.page.help');
    }

    /**
     * What works now: every active connection for who manages the company, the relevant ones for the others.
     *
     * @return array<string, Connection>
     */
    public function active(): array
    {
        return app(ConnectionCatalog::class)->forPage(auth()->user());
    }

    /**
     * What another system would add (owner and administrators only: they are the ones who buy).
     *
     * @return array<string, array{module: Module, connections: array<string, Connection>}>
     */
    public function potential(): array
    {
        return auth()->user()->canManageTenant() ? app(ConnectionCatalog::class)->potential() : [];
    }

    public function plansUrl(): string
    {
        return config('kiban.core.url').'/conta/sistemas';
    }
}
