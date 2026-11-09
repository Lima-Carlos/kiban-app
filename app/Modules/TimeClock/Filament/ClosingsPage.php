<?php

namespace App\Modules\TimeClock\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Closing;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Payroll\PayrollExport;
use App\Modules\TimeClock\Services\AejFile;
use App\Modules\TimeClock\Services\AfdFile;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Modules\TimeClock\Services\ScheduleCalendar;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\Services\TimeClockExporter;
use App\Modules\TimeClock\Services\TimesheetDocument;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Closing of the month (managers; viewers look and download): each employee with the month's totals and the
 * situation (open, closed, signed, disagreed). Close one, the selected or everyone (or by itself on a chosen day),
 * recalculate and reopen before the signature, remind who has not signed (bell or the link by WhatsApp), the time
 * sheet PDF (one or all in a .zip), the spreadsheet for the accountant and, in Brazil, the AFD file (preview).
 */
class ClosingsPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InModuleMenu;

    protected static string $moduleKey = TimeClockModule::KEY;

    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'ponto/fechamento';

    #[Url]
    public ?string $month = null;

    /** @var array<int, array<string, mixed>> */
    protected array $months = [];

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(TimeClockModule::KEY)
            && in_array(app(TimeClockAccess::class)->role(), [TimeClockRole::Manager, TimeClockRole::Viewer], true);
    }

    public static function canManage(): bool
    {
        return app(TimeClockAccess::class)->isManager() && app(TenantContext::class)->get()->license()->canWrite(TimeClockModule::KEY);
    }

    public static function getNavigationLabel(): string
    {
        return __('timeclock.closing.title');
    }

    /** Menu badge: the closed months the employee disagreed with. */
    public static function getNavigationBadge(): ?string
    {
        $disputed = Closing::query()->current()->whereNotNull('disputed_at')->whereNull('signed_at')->count();

        return $disputed > 0 ? (string) $disputed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getTitle(): string
    {
        return __('timeclock.closing.title').' · '.app(MonthClosing::class)->monthLabel($this->start());
    }

    public function getSubheading(): ?string
    {
        return __('timeclock.closing.help');
    }

    public function mount(): void
    {
        // By default the month that has just ended (the one to close).
        $this->month = preg_match('/^\d{4}-\d{2}$/', (string) $this->month) ? $this->month : now()->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * Texts of a window, one paragraph each (escaped).
     *
     * @param  array<int, string>  $texts
     */
    protected function paragraphs(array $texts): HtmlString
    {
        return new HtmlString(collect($texts)->filter()->map(fn (string $text): string => e($text))->implode('<br><br>'));
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->month.'-01');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(__('timeclock.closing.not_over'))
                ->color('warning')
                ->visible(fn (): bool => ! app(MonthClosing::class)->canClose($this->start(), FilamentTimezone::get())),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * The month of an employee (frozen copy or calculation), once per request.
     *
     * @return array<string, mixed>
     */
    public function monthOf(Employee $employee): array
    {
        return $this->months[$employee->getKey()] ??= app(TimesheetDocument::class)->month($employee, $this->start());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previous')
                ->label(__('timeclock.timesheet.previous'))
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->action(fn () => $this->month = $this->start()->subMonth()->format('Y-m')),
            Action::make('next')
                ->label(__('timeclock.timesheet.next'))
                ->icon(Heroicon::OutlinedChevronRight)
                ->color('gray')
                ->action(fn () => $this->month = $this->start()->addMonth()->format('Y-m')),
            ActionGroup::make([
                Action::make('spreadsheet')
                    ->label(__('timeclock.export.download'))
                    ->icon(Heroicon::OutlinedTableCells)
                    ->color('gray')
                    ->action(function (): BinaryFileResponse {
                        abort_unless(static::canAccess(), 403);
                        $path = app(TimeClockExporter::class)->month($this->start());

                        return response()->download($path, basename(preg_replace('/-[A-Za-z0-9]{6}\.xlsx$/', '.xlsx', $path)))->deleteFileAfterSend();
                    }),
                Action::make('payroll')
                    ->label(__('timeclock.payroll.download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(fn (): bool => static::canManage())
                    ->tooltip(__('timeclock.payroll.what'))
                    ->modalHeading(__('timeclock.payroll.title'))
                    ->modalDescription(fn (): HtmlString => $this->paragraphs([__('timeclock.payroll.what'), __('timeclock.payroll.registration_help'), ...app(PayrollExport::class)->warnings($this->start())]))
                    ->modalSubmitActionLabel(__('timeclock.payroll.save_download'))
                    ->fillForm(fn (): array => app(PayrollExport::class)->config())
                    ->schema([
                        Select::make('system')
                            ->label(__('timeclock.payroll.system'))
                            ->options(collect(PayrollExport::SYSTEMS)->mapWithKeys(fn (string $system): array => [$system => __('timeclock.payroll.systems.'.$system)])->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (?string $state, Set $set) => $state !== null ? $set('hours', PayrollExport::DEFAULT_HOURS[$state]) : null),
                        TextInput::make('company_code')
                            ->label(__('timeclock.payroll.company_code'))
                            ->helperText(__('timeclock.payroll.company_code_help'))
                            ->maxLength(20)
                            ->visible(fn (Get $get): bool => in_array($get('system'), ['dominio', 'alterdata', 'fortes', 'senior'], true)),
                        Select::make('hours')
                            ->label(__('timeclock.payroll.hours'))
                            ->options(collect(PayrollExport::HOUR_FORMATS)->mapWithKeys(fn (string $format): array => [$format => __('timeclock.payroll.hour_formats.'.$format)])->all())
                            ->required()
                            ->visible(fn (Get $get): bool => $get('system') !== 'rm' && $get('system') !== 'questor'),
                        TextInput::make('senior_codcal')->label(__('timeclock.payroll.senior_codcal'))->maxLength(5)->visible(fn (Get $get): bool => $get('system') === 'senior'),
                        TextInput::make('senior_tabeve')->label(__('timeclock.payroll.senior_tabeve'))->maxLength(3)->visible(fn (Get $get): bool => $get('system') === 'senior'),
                        TextInput::make('rm_pay_day')->label(__('timeclock.payroll.rm_pay_day'))->numeric()->minValue(1)->maxValue(28)->visible(fn (Get $get): bool => $get('system') === 'rm'),
                        TextInput::make('alterdata_process')->label(__('timeclock.payroll.alterdata_process'))->maxLength(1)->visible(fn (Get $get): bool => $get('system') === 'alterdata'),
                        Section::make(__('timeclock.payroll.codes'))
                            ->description(__('timeclock.payroll.codes_help'))
                            ->schema(collect(PayrollExport::EVENTS)->map(fn (string $event) => TextInput::make('codes.'.$event)->label(__('timeclock.payroll.events.'.$event))->maxLength(16))->all())
                            ->columns(2),
                    ])
                    ->action(function (array $data): StreamedResponse {
                        abort_unless(static::canManage(), 403);
                        $export = app(PayrollExport::class);
                        $export->save($data);
                        $month = $this->start();

                        return response()->streamDownload(fn () => print ($export->contents($month)), $export->fileName($month), ['Content-Type' => 'text/plain; charset=Windows-1252']);
                    }),
                Action::make('afd')
                    ->label(__('timeclock.afd.download'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->visible(fn (): bool => static::canManage() && TimeClockSetting::current()->isBrazil())
                    ->tooltip(__('timeclock.afd.what'))
                    ->modalHeading(__('timeclock.afd.title'))
                    ->modalDescription(fn (): HtmlString => $this->paragraphs([__('timeclock.afd.what'), __('timeclock.afd.preview'), ...app(AfdFile::class)->warnings($this->start(), $this->start()->endOfMonth())]))
                    ->modalSubmitActionLabel(__('timeclock.afd.download'))
                    ->action(function (): StreamedResponse {
                        abort_unless(static::canManage(), 403);
                        $afd = app(AfdFile::class);
                        [$from, $to] = [$this->start(), $this->start()->endOfMonth()];

                        return response()->streamDownload(fn () => print ($afd->contents($from, $to)), $afd->fileName($from, $to), ['Content-Type' => 'text/plain; charset=ISO-8859-1']);
                    }),
                Action::make('aej')
                    ->label(__('timeclock.aej.download'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->visible(fn (): bool => static::canManage() && TimeClockSetting::current()->isBrazil())
                    ->tooltip(__('timeclock.aej.what'))
                    ->modalHeading(__('timeclock.aej.title'))
                    ->modalDescription(fn (): HtmlString => $this->paragraphs([__('timeclock.aej.what'), __('timeclock.afd.preview'), ...app(AfdFile::class)->warnings($this->start(), $this->start()->endOfMonth())]))
                    ->modalSubmitActionLabel(__('timeclock.aej.download'))
                    ->action(function (): StreamedResponse {
                        abort_unless(static::canManage(), 403);
                        $aej = app(AejFile::class);
                        [$from, $to] = [$this->start(), $this->start()->endOfMonth()];

                        return response()->streamDownload(fn () => print ($aej->contents($from, $to)), $aej->fileName($from, $to), ['Content-Type' => 'text/plain; charset=ISO-8859-1']);
                    }),
                Action::make('zip')
                    ->label(__('timeclock.closing.zip'))
                    ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                    ->color('gray')
                    ->tooltip(__('timeclock.closing.zip_help'))
                    ->disabled(fn (): bool => app(MonthClosing::class)->employeesOf($this->start())->isEmpty())
                    ->action(function (): BinaryFileResponse {
                        abort_unless(static::canAccess(), 403);

                        return $this->zipOf(app(MonthClosing::class)->employeesOf($this->start()));
                    }),
            ])
                ->label(__('timeclock.closing.files'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->button(),
            Action::make('remind')
                ->label(__('timeclock.closing.remind'))
                ->icon(Heroicon::OutlinedBellAlert)
                ->color('gray')
                ->visible(fn (): bool => static::canManage() && app(MonthClosing::class)->waitingSignature($this->start())->isNotEmpty())
                ->badge(fn (): int => app(MonthClosing::class)->waitingSignature($this->start())->count())
                ->modalHeading(fn (): string => __('timeclock.closing.remind_title', ['month' => app(MonthClosing::class)->monthLabel($this->start())]))
                ->modalDescription(__('timeclock.closing.remind_help'))
                ->modalContent(fn () => view('timeclock.closing-remind', ['rows' => $this->signRows(app(MonthClosing::class)->waitingSignature($this->start()))]))
                ->modalSubmitActionLabel(__('timeclock.closing.remind_send'))
                ->action(function (): void {
                    abort_unless(static::canManage(), 403);
                    $sent = app(MonthClosing::class)->remind(app(MonthClosing::class)->waitingSignature($this->start()), auth()->user());
                    Notification::make()->success()->title(trans_choice('timeclock.closing.reminded', $sent, ['count' => $sent]))->send();
                }),
            Action::make('recalculateAll')
                ->label(__('timeclock.closing.recalculate_all'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => static::canManage() && $this->unsignedClosings()->isNotEmpty())
                ->requiresConfirmation()
                ->modalHeading(fn (): string => __('timeclock.closing.recalculate_all_title', ['month' => app(MonthClosing::class)->monthLabel($this->start())]))
                ->modalDescription(__('timeclock.closing.recalculate_help'))
                ->action(function (): void {
                    abort_unless(static::canManage(), 403);
                    $this->recalculateMany($this->unsignedClosings());
                }),
            Action::make('closeAll')
                ->label(__('timeclock.closing.close_all'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->visible(fn (): bool => static::canManage() && app(MonthClosing::class)->canClose($this->start(), FilamentTimezone::get()))
                // Nobody worked in this month (e.g. hired later): nothing to close.
                ->disabled(fn (): bool => app(MonthClosing::class)->employeesOf($this->start())->isEmpty())
                ->tooltip(fn (): ?string => app(MonthClosing::class)->employeesOf($this->start())->isEmpty() ? __('timeclock.closing.nobody') : null)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => __('timeclock.closing.close_all_title', ['month' => app(MonthClosing::class)->monthLabel($this->start())]))
                ->modalDescription(__('timeclock.closing.close_help'))
                ->action(function (): void {
                    abort_unless(static::canManage(), 403);
                    $this->report(app(MonthClosing::class)->closeAll($this->start(), auth()->user()), 'timeclock.closing.closed_count');
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $h = fn (int $minutes): string => $minutes === 0 ? '—' : ScheduleCalendar::hours($minutes);

        return $table
            ->query(function (): Builder {
                $ids = app(MonthClosing::class)->employeesOf($this->start())->modelKeys();

                return Employee::query()->with(['person', 'workplace'])->whereKey($ids);
            })
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('person.name')
                    ->label(__('timeclock.fields.name'))
                    ->weight('bold')
                    ->searchable()
                    ->url(fn (Employee $record): string => TimesheetPage::getUrl(['employee' => $record->getKey(), 'month' => $this->month]))
                    ->description(fn (Employee $record): ?string => $record->workplace?->name),
                TextColumn::make('status')
                    ->label(__('timeclock.closing.status'))
                    ->state(fn (Employee $record): string => $this->monthOf($record)['closing']?->status() ?? Closing::OPEN)
                    ->formatStateUsing(fn (string $state): string => __('timeclock.closing.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Closing::SIGNED => 'success',
                        Closing::DISPUTED => 'danger',
                        Closing::CLOSED => 'info',
                        default => 'gray',
                    })
                    ->description(fn (Employee $record): ?string => $this->monthOf($record)['closing']?->dispute_reason),
                TextColumn::make('worked')
                    ->label(__('timeclock.timesheet.totals.worked'))
                    ->state(fn (Employee $record): string => $h($this->monthOf($record)['totals']['worked']))
                    ->alignEnd(),
                TextColumn::make('extra')
                    ->label(__('timeclock.timesheet.totals.extra'))
                    ->state(fn (Employee $record): string => $h($this->monthOf($record)['totals']['extra'] + $this->monthOf($record)['totals']['extra_rest']))
                    ->color('success')
                    ->alignEnd(),
                TextColumn::make('deficit')
                    ->label(__('timeclock.timesheet.totals.deficit'))
                    ->state(fn (Employee $record): string => $h($this->monthOf($record)['totals']['deficit'] - $this->monthOf($record)['totals']['absence_minutes']))
                    ->color('danger')
                    ->alignEnd(),
                TextColumn::make('absences')
                    ->label(__('timeclock.timesheet.totals.absences'))
                    ->state(fn (Employee $record): int => $this->monthOf($record)['totals']['absences'])
                    ->alignEnd(),
                TextColumn::make('missing')
                    ->label(__('timeclock.closing.missing'))
                    ->tooltip(__('timeclock.closing.missing_help'))
                    ->state(fn (Employee $record): int => (int) ($this->monthOf($record)['totals']['missing_punches'] ?? 0))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->alignEnd(),
                TextColumn::make('pending')
                    ->label(__('timeclock.closing.pending'))
                    ->state(fn (Employee $record): int => Adjustment::query()->where('employee_id', $record->getKey())->where('status', Adjustment::PENDING)
                        ->whereBetween('date', [$this->start()->toDateString(), $this->start()->endOfMonth()->toDateString()])->count())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('situation')
                    ->label(__('timeclock.closing.status'))
                    ->options(collect([Closing::OPEN, Closing::CLOSED, Closing::SIGNED, Closing::DISPUTED])->mapWithKeys(fn (string $status): array => [$status => __('timeclock.closing.statuses.'.$status)])->all())
                    ->query(function (Builder $query, array $data): void {
                        if (blank($data['value'] ?? null)) {
                            return;
                        }

                        $month = $this->start()->toDateString();
                        $closings = fn ($sub) => $sub->whereNull('reopened_at')->whereDate('month', $month);

                        match ($data['value']) {
                            Closing::OPEN => $query->whereNotIn('id', Closing::query()->select('employee_id')->tap($closings)),
                            Closing::SIGNED => $query->whereIn('id', Closing::query()->select('employee_id')->tap($closings)->whereNotNull('signed_at')),
                            Closing::DISPUTED => $query->whereIn('id', Closing::query()->select('employee_id')->tap($closings)->whereNull('signed_at')->whereNotNull('disputed_at')),
                            default => $query->whereIn('id', Closing::query()->select('employee_id')->tap($closings)->whereNull('signed_at')->whereNull('disputed_at')),
                        };
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('closeSelected')
                        ->label(__('timeclock.closing.close_selected'))
                        ->icon(Heroicon::OutlinedLockClosed)
                        ->visible(fn (): bool => static::canManage() && app(MonthClosing::class)->canClose($this->start(), FilamentTimezone::get()))
                        ->requiresConfirmation()
                        ->modalDescription(__('timeclock.closing.close_help'))
                        ->action(function (Collection $records): void {
                            abort_unless(static::canManage(), 403);
                            $result = ['closed' => 0, 'skipped' => []];

                            foreach ($records as $employee) {
                                if (app(MonthClosing::class)->isClosed($employee, $this->start())) {
                                    continue;
                                }

                                try {
                                    app(MonthClosing::class)->close($employee, $this->start(), auth()->user());
                                    $result['closed']++;
                                } catch (PersonRuleException $exception) {
                                    $result['skipped'][(string) $employee->person?->name] = $exception->getMessage();
                                }
                            }

                            $this->report($result, 'timeclock.closing.closed_count');
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('recalculateSelected')
                        ->label(__('timeclock.closing.recalculate'))
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->visible(fn (): bool => static::canManage())
                        ->requiresConfirmation()
                        ->modalDescription(__('timeclock.closing.recalculate_help'))
                        ->action(function (Collection $records): void {
                            abort_unless(static::canManage(), 403);
                            $this->recalculateMany($this->unsignedClosings()->whereIn('employee_id', $records->modelKeys()));
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('reopenSelected')
                        ->label(__('timeclock.closing.reopen'))
                        ->icon(Heroicon::OutlinedLockOpen)
                        ->visible(fn (): bool => static::canManage())
                        ->modalDescription(__('timeclock.closing.reopen_help'))
                        ->schema([Textarea::make('reason')->label(__('timeclock.adjustments.reason'))->rows(2)->required()])
                        ->action(function (Collection $records, array $data): void {
                            abort_unless(static::canManage(), 403);
                            $result = ['closed' => 0, 'skipped' => []];

                            foreach ($this->unsignedClosings()->whereIn('employee_id', $records->modelKeys()) as $closing) {
                                try {
                                    app(MonthClosing::class)->reopen($closing, auth()->user(), (string) $data['reason']);
                                    $result['closed']++;
                                } catch (PersonRuleException $exception) {
                                    $result['skipped'][(string) $closing->employee?->person?->name] = $exception->getMessage();
                                }
                            }

                            $this->report($result, 'timeclock.closing.reopened_count');
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('remindSelected')
                        ->label(__('timeclock.closing.remind'))
                        ->icon(Heroicon::OutlinedBellAlert)
                        ->visible(fn (): bool => static::canManage())
                        ->modalHeading(fn (): string => __('timeclock.closing.remind_title', ['month' => app(MonthClosing::class)->monthLabel($this->start())]))
                        ->modalDescription(__('timeclock.closing.remind_help'))
                        ->modalContent(fn (Collection $records) => view('timeclock.closing-remind', ['rows' => $this->signRows(app(MonthClosing::class)->waitingSignature($this->start())->whereIn('employee_id', $records->modelKeys()))]))
                        ->modalSubmitActionLabel(__('timeclock.closing.remind_send'))
                        ->action(function (Collection $records): void {
                            abort_unless(static::canManage(), 403);
                            $sent = app(MonthClosing::class)->remind(app(MonthClosing::class)->waitingSignature($this->start())->whereIn('employee_id', $records->modelKeys()), auth()->user());
                            Notification::make()->success()->title(trans_choice('timeclock.closing.reminded', $sent, ['count' => $sent]))->send();
                        }),
                    BulkAction::make('zipSelected')
                        ->label(__('timeclock.closing.zip_selected'))
                        ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                        ->action(fn (Collection $records): BinaryFileResponse => $this->zipOf($records->sortBy(fn (Employee $employee): string => mb_strtolower((string) $employee->person?->name)))),
                ]),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label(__('timeclock.closing.pdf'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->link()
                    ->action(function (Employee $record): StreamedResponse {
                        $document = app(TimesheetDocument::class);

                        return response()->streamDownload(fn () => print ($document->pdf($record, $this->start())), $document->fileName($record, $this->start()), ['Content-Type' => 'application/pdf']);
                    }),
                Action::make('signLink')
                    ->label(__('timeclock.closing.sign_link'))
                    ->icon(Heroicon::OutlinedLink)
                    ->link()
                    ->visible(fn (Employee $record): bool => static::canManage() && ($closing = $this->monthOf($record)['closing']) !== null && ! $closing->isSigned() && $closing->disputed_at === null)
                    ->modalHeading(fn (Employee $record): string => __('timeclock.closing.sign_link_title', ['name' => $record->person?->name]))
                    ->modalDescription(__('timeclock.closing.sign_link_help'))
                    ->modalContent(fn (Employee $record) => view('timeclock.closing-remind', ['rows' => $this->signRows(collect([$this->monthOf($record)['closing']]))]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('timeclock.privacy.close')),
                Action::make('close')
                    ->label(__('timeclock.closing.close'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->visible(fn (Employee $record): bool => static::canManage() && $this->monthOf($record)['closing'] === null)
                    ->requiresConfirmation()
                    ->modalDescription(__('timeclock.closing.close_help'))
                    ->action(function (Employee $record, Action $action): void {
                        abort_unless(static::canManage(), 403);
                        $this->attempt(fn () => app(MonthClosing::class)->close($record, $this->start(), auth()->user()), $action, __('timeclock.closing.closed'));
                    }),
                Action::make('reopen')
                    ->label(__('timeclock.closing.reopen'))
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('gray')
                    ->visible(fn (Employee $record): bool => static::canManage() && ($closing = $this->monthOf($record)['closing']) !== null && ! $closing->isSigned())
                    ->modalDescription(__('timeclock.closing.reopen_help'))
                    ->schema([Textarea::make('reason')->label(__('timeclock.adjustments.reason'))->rows(2)->required()])
                    ->action(function (Employee $record, array $data, Action $action): void {
                        abort_unless(static::canManage(), 403);
                        $closing = app(MonthClosing::class)->current($record, $this->start()) ?? abort(404);
                        $this->attempt(fn () => app(MonthClosing::class)->reopen($closing, auth()->user(), (string) $data['reason']), $action, __('timeclock.closing.reopened'));
                    }),
            ])
            ->emptyStateHeading(__('timeclock.employees.empty'));
    }

    /**
     * Closings of the month in force and not signed yet (they may be recalculated or reopened).
     *
     * @return EloquentCollection<int, Closing>
     */
    public function unsignedClosings(): EloquentCollection
    {
        return Closing::query()->current()->with('employee.person')
            ->whereDate('month', $this->start()->toDateString())
            ->whereNull('signed_at')
            ->get();
    }

    /**
     * @param  iterable<int, Closing>  $closings
     */
    protected function recalculateMany(iterable $closings): void
    {
        $result = ['closed' => 0, 'skipped' => []];

        foreach ($closings as $closing) {
            try {
                app(MonthClosing::class)->recalculate($closing, auth()->user());
                $result['closed']++;
            } catch (PersonRuleException $exception) {
                $result['skipped'][(string) $closing->employee?->person?->name] = $exception->getMessage();
            }
        }

        $this->report($result, 'timeclock.closing.recalculated_count');
    }

    /**
     * @param  array{closed: int, skipped: array<string, string>}  $result
     */
    protected function report(array $result, string $key): void
    {
        $this->months = [];
        $notification = Notification::make()->title(trans_choice($key, $result['closed'], ['count' => $result['closed']]));

        if ($result['skipped'] !== []) {
            $notification->warning()->body(collect($result['skipped'])->map(fn (string $why, string $name): string => "{$name}: {$why}")->implode("\n"))->persistent();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    /**
     * The rows of the "remind" and "link" windows: the person, how they get the notice and the link.
     *
     * @param  iterable<int, Closing|null>  $closings
     * @return array<int, array<string, mixed>>
     */
    protected function signRows(iterable $closings): array
    {
        $service = app(MonthClosing::class);
        $rows = [];

        foreach ($closings as $closing) {
            if ($closing === null) {
                continue;
            }

            $rows[] = [
                'name' => $closing->employee?->person?->name,
                'login' => $closing->employee?->person?->user_id !== null,
                'link' => $service->signUrl($closing),
                'whatsapp' => $service->whatsappUrl($closing),
                'reminded' => $closing->reminded_at?->setTimezone(FilamentTimezone::get())->translatedFormat(__('app.datetime_format')),
            ];
        }

        return $rows;
    }

    /**
     * @param  iterable<int, Employee>  $employees
     */
    protected function zipOf(iterable $employees): BinaryFileResponse
    {
        $document = app(TimesheetDocument::class);

        return response()->download($document->zip($employees, $this->start()), $document->zipName($this->start()))->deleteFileAfterSend();
    }

    protected function attempt(callable $callback, Action $action, string $success): void
    {
        try {
            $callback();
        } catch (PersonRuleException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            $action->halt();
        }

        $this->months = [];
        Notification::make()->success()->title($success)->send();
    }
}
