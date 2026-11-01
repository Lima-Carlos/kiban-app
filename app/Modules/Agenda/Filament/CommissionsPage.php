<?php

namespace App\Modules\Agenda\Filament;

use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Services\AgendaAccess;
use App\Modules\Agenda\Services\AgendaReports;
use App\Modules\Concerns\InModuleMenu;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Commissions of the month (manager): per professional, the appointments done (pet ready or delivered), the
 * revenue and the commission (% per service, kept on each appointment); the Excel file for the payroll.
 */
class CommissionsPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = AgendaModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'agenda/comissoes';

    protected string $view = 'agenda.commissions';

    /** "2026-11" */
    public string $month = '';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(AgendaModule::KEY) && app(AgendaAccess::class)->isManager();
    }

    public static function getNavigationLabel(): string
    {
        return __('agenda.commissions.title');
    }

    public function getTitle(): string
    {
        return __('agenda.commissions.title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->month = CarbonImmutable::now(AgendaSetting::timezone())->format('Y-m');
    }

    public function monthDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01', AgendaSetting::timezone())->startOfMonth();
    }

    public function previous(): void
    {
        $this->month = $this->monthDate()->subMonth()->format('Y-m');
    }

    public function next(): void
    {
        $this->month = $this->monthDate()->addMonth()->format('Y-m');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return app(AgendaReports::class)->commissions($this->monthDate());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('agenda.commissions.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): BinaryFileResponse => response()
                    ->download(app(AgendaReports::class)->exportCommissions($this->monthDate()), __('agenda.commissions.file', ['month' => $this->month]).'.xlsx')
                    ->deleteFileAfterSend()),
        ];
    }
}
