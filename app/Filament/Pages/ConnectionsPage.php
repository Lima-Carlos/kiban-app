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
 * "Systems together" (Company group): what the company's systems do together now and, for the owner and the
 * administrators, what another system would add ("With the Ponto, you would also have: ...", with the link to the
 * plans in the Kiban account). Helps the customer use (and buy) the second system without pushing.
 */
class ConnectionsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $slug = 'empresa/sistemas-juntos';

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
        $catalog = app(ConnectionCatalog::class);

        return auth()->user()->canManageTenant() ? $catalog->active() : $catalog->relevant(auth()->user());
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
