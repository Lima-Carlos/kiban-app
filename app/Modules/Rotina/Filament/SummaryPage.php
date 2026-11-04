<?php

namespace App\Modules\Rotina\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Rotina\RotinaModule;
use App\Modules\Rotina\Services\RotinaAccess;
use App\Modules\Rotina\Services\RotinaReports;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use App\Models\User;
use App\Modules\Rotina\Models\Entry;
use App\Modules\Rotina\Models\Rate;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use App\Support\Country;
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

    /**
     * People who log hours (last 6 months) or already have a value.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function people(): \Illuminate\Support\Collection
    {
        $ids = Entry::query()->where('worked_on', '>=', now()->subMonths(6)->toDateString())->distinct()->pluck('user_id')
            ->merge(Rate::query()->pluck('user_id'))->unique();

        return User::query()->whereKey($ids)->orderBy('name')->get()->toBase();
    }

    protected function getHeaderActions(): array
    {
        return [
            // What an hour of each person costs (salary and charges ÷ hours of the month): only the manager sees it.
            Action::make('rates')
                ->label(__('rotina.rates.action'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('gray')
                ->modalDescription(__('rotina.rates.help'))
                ->fillForm(fn (): array => ['rates' => Rate::query()->pluck('hour_cents', 'user_id')->map(fn (int $cents): float => $cents / 100)->mapWithKeys(fn ($value, $id): array => ['u'.$id => $value])->all()])
                ->schema(fn (): array => $this->people()->map(fn (User $user): TextInput => TextInput::make('rates.u'.$user->getKey())
                    ->label($user->name)->numeric()->minValue(0)->step(0.01)->prefix(Country::currency())->suffix(__('rotina.rates.per_hour')))->all())
                ->action(function (array $data): void {
                    app(RotinaReports::class)->setRates(collect($data['rates'] ?? [])->mapWithKeys(fn ($value, string $key): array => [(int) substr($key, 1) => $value])->all());
                    Notification::make()->success()->title(__('rotina.rates.saved'))->send();
                }),
            Action::make('export')
                ->label(__('rotina.reports.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): BinaryFileResponse => response()
                    ->download(app(RotinaReports::class)->export($this->monthDate()), __('rotina.reports.file', ['month' => $this->month]).'.xlsx')
                    ->deleteFileAfterSend()),
        ];
    }
}
