<?php

namespace App\Modules\Rotina\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Rotina\RotinaModule;
use App\Modules\Rotina\Services\RotinaAccess;
use App\Modules\Rotina\Services\RotinaReports;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Summary of the month (manager): hours per project/client and per person, tasks done, and the spreadsheet.
 */
class SummaryPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = RotinaModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'rotina/resumo';

    protected string $view = 'rotina.summary';

    /** "2026-11" */
    public string $month = '';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(RotinaModule::KEY) && app(RotinaAccess::class)->isManager();
    }

    public static function getNavigationLabel(): string
    {
        return __('rotina.reports.title');
    }

    public function getTitle(): string
    {
        return __('rotina.reports.title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->month = CarbonImmutable::now()->format('Y-m');
    }

    public function monthDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
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
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return app(RotinaReports::class)->month($this->monthDate());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('rotina.reports.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): BinaryFileResponse => response()
                    ->download(app(RotinaReports::class)->export($this->monthDate()), __('rotina.reports.file', ['month' => $this->month]).'.xlsx')
                    ->deleteFileAfterSend()),
        ];
    }
}
