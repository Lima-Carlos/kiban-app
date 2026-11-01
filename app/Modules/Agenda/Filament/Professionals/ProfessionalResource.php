<?php

namespace App\Modules\Agenda\Filament\Professionals;

use App\Filament\Pages\PeoplePage;
use App\Filament\Support\ModuleResource;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Filament\AgendaFields;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Models\Service;
use App\Modules\Agenda\Models\TimeOff;
use App\Modules\Agenda\Services\AgendaFormat;
use App\Modules\Agenda\Services\WorkingHours;
use App\Modules\TimeClock\Filament\Schedules\ScheduleResource;
use App\Modules\TimeClock\Services\ScheduleCalendar;
use App\Modules\TimeClock\TimeClockModule;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Professionals of the agenda (manager): people of the "People" screen who attend. Here: hours (the Ponto schedule
 * or their own week, with the break), the services each one does with the commission, time off, color and whether
 * they show on the booking page. Adding and turning off is in "People" (one register of people for every system).
 */
class ProfessionalResource extends ModuleResource
{
    protected static string $moduleKey = AgendaModule::KEY;

    protected static ?string $model = Professional::class;

    protected static ?string $langGroup = 'agenda.professionals';

    protected static ?string $slug = 'agenda/profissionais';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('agenda.professionals.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('agenda.professionals.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('agenda.professionals.plural_title');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['person', 'services']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                ColorColumn::make('color')
                    ->label('')
                    ->state(fn (Professional $record): string => $record->displayColor()),
                TextColumn::make('person.name')
                    ->label(__('agenda.fields.name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('hours')
                    ->label(__('agenda.fields.hours'))
                    ->state(fn (Professional $record): string => static::hoursSummary($record))
                    ->wrap(),
                TextColumn::make('services')
                    ->label(__('agenda.fields.services'))
                    ->state(fn (Professional $record): string => $record->services->isEmpty() ? __('agenda.professionals.all_services') : $record->services->pluck('name')->implode(', '))
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('commission_bp')
                    ->label(__('agenda.fields.commission'))
                    ->formatStateUsing(fn (int $state): string => AgendaFormat::percent($state))
                    ->visibleFrom('md'),
                TextColumn::make('next_off')
                    ->label(__('agenda.professionals.next_off'))
                    ->state(fn (Professional $record): ?string => ($off = $record->timeOff()->where('ends_at', '>', now())->first())
                        ? AgendaFormat::when($off->starts_at).' – '.AgendaFormat::when($off->ends_at) : null)
                    ->placeholder('—')
                    ->visibleFrom('lg'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('app.people.situation'))
                    ->trueLabel(__('app.people.active_only'))
                    ->falseLabel(__('app.people.inactive_only'))
                    ->default(true),
            ])
            ->recordActions([
                Action::make('setup')
                    ->label(__('agenda.professionals.setup'))
                    ->icon(Heroicon::OutlinedClock)
                    ->link()
                    ->authorize('update')
                    ->modalWidth('4xl')
                    ->fillForm(fn (Professional $record): array => [
                        'color' => $record->displayColor(),
                        'online' => $record->online,
                        'follows_time_clock' => $record->follows_time_clock,
                        'commission_bp' => $record->commission_bp,
                        'week' => $record->week ?: WorkingHours::defaultWeek(),
                        'services' => $record->services->map(fn (Service $service): array => ['service_id' => $service->getKey(), 'commission_bp' => $service->pivot->commission_bp])->all(),
                    ])
                    ->schema(fn (): array => static::setupFields())
                    ->action(function (Professional $record, array $data): void {
                        $record->update([
                            'color' => $data['color'] ?? null,
                            'online' => (bool) ($data['online'] ?? true),
                            'follows_time_clock' => (bool) ($data['follows_time_clock'] ?? true),
                            'commission_bp' => (int) ($data['commission_bp'] ?? 0),
                            'week' => $data['week'] ?? $record->week,
                        ]);
                        $record->syncServices((array) ($data['services'] ?? []));
                        Notification::make()->success()->title(__('agenda.professionals.saved'))->send();
                    }),
                ActionGroup::make([
                    Action::make('timeOff')
                        ->label(__('agenda.professionals.add_off'))
                        ->icon(Heroicon::OutlinedSun)
                        ->authorize('update')
                        ->schema([
                            Grid::make(['default' => 1, 'sm' => 2])->components([
                                DateTimePicker::make('starts_at')->label(__('agenda.fields.from'))->seconds(false)->timezone(AgendaSetting::timezone())->required(),
                                DateTimePicker::make('ends_at')->label(__('agenda.fields.until'))->seconds(false)->timezone(AgendaSetting::timezone())->after('starts_at')->required(),
                            ]),
                            TextInput::make('reason')->label(__('agenda.fields.reason'))->maxLength(160),
                        ])
                        ->action(function (Professional $record, array $data): void {
                            TimeOff::create(['professional_id' => $record->getKey(), 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'reason' => $data['reason'] ?? null]);
                            Notification::make()->success()->title(__('agenda.professionals.off_saved'))->send();
                        }),
                    Action::make('removeOff')
                        ->label(__('agenda.professionals.remove_off'))
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->authorize('update')
                        ->visible(fn (Professional $record): bool => $record->timeOff()->where('ends_at', '>', now())->exists())
                        ->schema(fn (Professional $record): array => [
                            Select::make('time_off_id')
                                ->label(__('agenda.professionals.which_off'))
                                ->options($record->timeOff()->where('ends_at', '>', now())->get()->mapWithKeys(fn (TimeOff $off): array => [
                                    $off->getKey() => AgendaFormat::when($off->starts_at).' – '.AgendaFormat::when($off->ends_at).($off->reason ? ' · '.$off->reason : ''),
                                ])->all())
                                ->required(),
                        ])
                        ->action(function (Professional $record, array $data): void {
                            TimeOff::query()->where('professional_id', $record->getKey())->whereKey($data['time_off_id'])->delete();
                            Notification::make()->success()->title(__('agenda.professionals.off_removed'))->send();
                        }),
                    Action::make('person')
                        ->label(__('agenda.professionals.open_people'))
                        ->icon(Heroicon::OutlinedUser)
                        ->url(fn (): string => PeoplePage::getUrl()),
                ])->label(__('agenda.actions.more'))->link(),
            ])
            ->emptyStateHeading(__('agenda.professionals.empty'))
            ->emptyStateDescription(__('agenda.professionals.empty_help'));
    }

    /**
     * @return array<int, mixed>
     */
    protected static function setupFields(): array
    {
        $hasClock = (bool) app(TenantContext::class)->current()?->license()->hasModule(TimeClockModule::KEY);

        return [
            Grid::make(['default' => 1, 'sm' => 3])->components([
                ColorPicker::make('color')->label(__('agenda.fields.color')),
                AgendaFields::percent()->label(__('agenda.fields.commission'))->helperText(__('agenda.helpers.commission'))->required(),
                Toggle::make('online')->label(__('agenda.fields.online_professional'))->inline(false),
            ]),
            Toggle::make('follows_time_clock')
                ->label(__('agenda.fields.follows_time_clock'))
                ->helperText(__('agenda.helpers.follows_time_clock'))
                ->visible($hasClock)
                ->live(),
            Section::make(__('agenda.professionals.week'))
                ->description($hasClock ? __('agenda.professionals.week_help_clock') : __('agenda.professionals.week_help'))
                ->compact()
                ->collapsible()
                ->components(collect(range(1, 7))->map(fn (int $weekday) => ScheduleResource::dayFields($weekday))->all()),
            Section::make(__('agenda.professionals.services'))
                ->description(__('agenda.professionals.services_help'))
                ->compact()
                ->components([
                    Repeater::make('services')
                        ->hiddenLabel()
                        ->addActionLabel(__('agenda.professionals.add_service'))
                        ->defaultItems(0)
                        ->columns(['default' => 1, 'sm' => 2])
                        ->components([
                            Select::make('service_id')
                                ->label(__('agenda.fields.service'))
                                ->options(fn (): array => Service::query()->active()->orderBy('sort')->pluck('name', 'id')->all())
                                ->distinct()
                                ->required(),
                            AgendaFields::percent()
                                ->label(__('agenda.fields.commission'))
                                ->placeholder(fn (Get $get): string => __('agenda.professionals.default_commission')),
                        ]),
                ]),
        ];
    }

    /** "Ponto: Comercial" or "Seg 08:00–18:00 · Sáb 08:00–13:00". */
    public static function hoursSummary(Professional $professional): string
    {
        $hours = app(WorkingHours::class);
        $employee = $hours->employee($professional);
        $schedule = $employee ? app(ScheduleCalendar::class)->scheduleOn($employee, CarbonImmutable::today()) : null;

        if ($schedule !== null) {
            return __('agenda.professionals.from_time_clock', ['name' => $schedule->name]);
        }

        return app(ScheduleCalendar::class)->summary($hours->ownSchedule($professional));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageProfessionals::route('/'),
        ];
    }

    /** "Add professional" opens the People form (the same person in every system). */
    public static function addUrl(): string
    {
        return PeoplePage::getUrl(['add' => 1]);
    }
}
