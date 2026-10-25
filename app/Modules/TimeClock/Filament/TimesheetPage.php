<?php

namespace App\Modules\TimeClock\Filament;

use App\Modules\ModuleRegistry;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Services\AdjustmentService;
use App\Modules\TimeClock\Services\DayCalculator;
use App\Modules\TimeClock\Services\HourBank;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * The month of an employee, day by day: expected hours, punches (added ones marked "I", left-out ones
 * crossed), worked, overtime, minutes short, absences, night minutes, warnings, the totals and the hour bank.
 * For the employee it is "My time sheet" (asks corrections); for the manager it is "Calculation" (any
 * employee; corrects at once). Viewers look.
 */
class TimesheetPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'ponto/apuracao';

    protected string $view = 'timeclock.timesheet-page';

    #[Url(as: 'employee')]
    public ?int $employeeId = null;

    #[Url]
    public ?string $month = null;

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(TimeClockModule::KEY) && auth()->check()
            && (static::seesEveryone() || app(TimeClockAccess::class)->employeeFor(auth()->user()) !== null);
    }

    public static function seesEveryone(): bool
    {
        return in_array(app(TimeClockAccess::class)->role(), [TimeClockRole::Manager, TimeClockRole::Viewer], true);
    }

    public static function getNavigationGroup(): ?string
    {
        return app(ModuleRegistry::class)->get(TimeClockModule::KEY)->name();
    }

    public static function getNavigationLabel(): string
    {
        return static::seesEveryone() ? __('timeclock.timesheet.title') : __('timeclock.timesheet.mine');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        if (! static::seesEveryone()) {
            $this->employeeId = app(TimeClockAccess::class)->employeeFor(auth()->user())?->getKey();
        }

        $this->employeeId ??= Employee::query()->active()->orderBy('id')->value('id');
        $this->month = preg_match('/^\d{4}-\d{2}$/', (string) $this->month) ? $this->month : now()->format('Y-m');
    }

    public function employee(): ?Employee
    {
        if ($this->employeeId === null) {
            return null;
        }

        // Someone who is not a manager or viewer only ever sees their own month.
        if (! static::seesEveryone() && $this->employeeId !== app(TimeClockAccess::class)->employeeFor(auth()->user())?->getKey()) {
            abort(403);
        }

        return Employee::query()->with(['person', 'workplace'])->find($this->employeeId);
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->month.'-01');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function days(): array
    {
        $employee = $this->employee();

        return $employee ? app(DayCalculator::class)->period($employee, $this->start(), $this->start()->endOfMonth()) : [];
    }

    /**
     * @return array<string, int>
     */
    public function totals(array $days): array
    {
        return app(DayCalculator::class)->totals($days);
    }

    /**
     * @return array{balance: int, credits: int, debits: int, expired: int}|null
     */
    public function bank(): ?array
    {
        $employee = $this->employee();

        if ($employee === null || ! app(HourBank::class)->enabled()) {
            return null;
        }

        $end = $this->start()->endOfMonth();

        return app(HourBank::class)->balance($employee, $end->isFuture() ? CarbonImmutable::today() : $end);
    }

    /**
     * @return array<int, string>
     */
    public function employeeOptions(): array
    {
        return Employee::query()->with('person')->get()
            ->mapWithKeys(fn (Employee $employee): array => [$employee->getKey() => $employee->person?->name.($employee->is_active ? '' : ' ('.__('app.people.statuses.inactive').')')])
            ->sort()
            ->all();
    }

    public function previousMonth(): void
    {
        $this->month = $this->start()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->start()->addMonth()->format('Y-m');
    }

    public function canCorrect(): bool
    {
        return app(TimeClockAccess::class)->isManager() && app(TenantContext::class)->get()->license()->canWrite(TimeClockModule::KEY);
    }

    public function canAsk(): bool
    {
        $own = app(TimeClockAccess::class)->employeeFor(auth()->user());

        return $own !== null && $own->getKey() === $this->employeeId && app(TenantContext::class)->get()->license()->canWrite(TimeClockModule::KEY);
    }

    /** "Correct" (manager, approved at once) or "Ask a correction" (the employee). */
    public function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(fn (): string => $this->canCorrect() ? __('timeclock.adjustments.correct') : __('timeclock.adjustments.ask'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->iconButton()
            ->visible(fn (): bool => $this->canCorrect() || $this->canAsk())
            ->modalHeading(fn (array $arguments): string => __('timeclock.adjustments.modal_title', ['date' => CarbonImmutable::parse($arguments['date'])->translatedFormat(__('app.date_format'))]))
            ->schema(fn (array $arguments): array => [
                ToggleButtons::make('type')
                    ->label(__('timeclock.adjustments.what'))
                    ->options(collect(Adjustment::TYPES)->mapWithKeys(fn (string $type): array => [$type => __('timeclock.adjustments.types.'.$type)])->all())
                    ->default(Adjustment::ADD_PUNCH)
                    ->inline()
                    ->required()
                    ->live(),
                TimePicker::make('time')
                    ->label(__('timeclock.adjustments.time'))
                    ->seconds(false)
                    ->format('H:i')
                    ->visible(fn (Get $get): bool => $get('type') === Adjustment::ADD_PUNCH)
                    ->required(fn (Get $get): bool => $get('type') === Adjustment::ADD_PUNCH),
                Toggle::make('next_day')
                    ->label(__('timeclock.adjustments.next_day'))
                    ->helperText(__('timeclock.adjustments.next_day_help'))
                    ->visible(fn (Get $get): bool => $get('type') === Adjustment::ADD_PUNCH),
                Select::make('punch_id')
                    ->label(__('timeclock.adjustments.which_punch'))
                    ->options(fn (): array => $this->punchOptions($arguments['date']))
                    ->visible(fn (Get $get): bool => $get('type') === Adjustment::IGNORE_PUNCH)
                    ->required(fn (Get $get): bool => $get('type') === Adjustment::IGNORE_PUNCH),
                Toggle::make('whole_day')
                    ->label(__('timeclock.adjustments.whole_day'))
                    ->visible(fn (Get $get): bool => $get('type') === Adjustment::EXCUSE)
                    ->live(),
                TextInput::make('minutes')
                    ->label(__('timeclock.adjustments.minutes'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(1440)
                    ->suffix('min')
                    ->visible(fn (Get $get): bool => $get('type') === Adjustment::EXCUSE && ! $get('whole_day'))
                    ->required(fn (Get $get): bool => $get('type') === Adjustment::EXCUSE && ! $get('whole_day')),
                Textarea::make('reason')
                    ->label(__('timeclock.adjustments.reason'))
                    ->placeholder(__('timeclock.adjustments.reason_placeholder'))
                    ->rows(2)
                    ->required(),
                FileUpload::make('attachment_path')
                    ->label(__('timeclock.adjustments.attachment'))
                    ->helperText(__('timeclock.adjustments.attachment_help'))
                    ->disk('local')
                    ->visibility('private')
                    ->directory(fn (): string => 'tenants/'.app(TenantContext::class)->get()->getKey().'/timeclock/adjustments')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                    ->automaticallyResizeImagesMode('contain')
                    ->automaticallyResizeImagesToWidth('1600')
                    ->automaticallyResizeImagesToHeight('1600')
                    ->maxSize(10240),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $employee = $this->employee() ?? abort(404);
                $data['date'] = $arguments['date'];
                $service = app(AdjustmentService::class);

                try {
                    $this->canCorrect() ? $service->record($employee, $data, auth()->user()) : $service->request($employee, $data, auth()->user());
                } catch (PersonRuleException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title($this->canCorrect() ? __('timeclock.adjustments.recorded') : __('timeclock.adjustments.requested'))->send();
            });
    }

    /**
     * The punches around a day, to choose one to leave out.
     *
     * @return array<int, string>
     */
    protected function punchOptions(string $date): array
    {
        $day = CarbonImmutable::parse($date, app(DayCalculator::class)->timezone($this->employee()));

        return Punch::query()->where('employee_id', $this->employeeId)
            ->whereBetween('punched_at', [$day->subHours(12)->utc(), $day->addHours(36)->utc()])
            ->orderBy('punched_at')
            ->get()
            ->mapWithKeys(fn (Punch $punch): array => [$punch->getKey() => $punch->localTime()->translatedFormat(__('app.date_format')).' '.$punch->localTime()->format('H:i').' · #'.$punch->nsr])
            ->all();
    }
}
