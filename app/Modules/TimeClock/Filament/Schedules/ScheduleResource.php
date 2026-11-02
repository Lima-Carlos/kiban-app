<?php

namespace App\Modules\TimeClock\Filament\Schedules;

use App\Filament\Support\TimeInput;
use App\Filament\Support\ModuleResource;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Services\ScheduleCalendar;
use App\Modules\TimeClock\Services\ScheduleService;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PersonRuleException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Work schedules (managers): weekly hours or a rotation like 12x36, starting from ready models.
 * A schedule already given to someone is duplicated to change (past days keep their rules).
 */
class ScheduleResource extends ModuleResource
{
    protected static string $moduleKey = TimeClockModule::KEY;

    protected static ?string $model = Schedule::class;

    protected static ?string $langGroup = 'timeclock.schedules';

    protected static ?string $slug = 'ponto/horarios';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 15;

    public static function getModelLabel(): string
    {
        return __('timeclock.schedules.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('timeclock.schedules.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('timeclock.schedules.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('timeclock.schedules.singular_title');
    }

    public static function form(Schema $schema): Schema
    {
        $isWeekly = fn (Get $get): bool => $get('type') !== Schedule::CYCLE;

        return $schema
            ->columns(1)
            ->components([
                Select::make('preset')
                    ->label(__('timeclock.schedules.preset'))
                    ->helperText(__('timeclock.schedules.preset_help'))
                    ->options(collect(array_keys(ScheduleService::presets()))->mapWithKeys(fn (string $key): array => [$key => __('timeclock.schedules.presets.'.$key)])->all())
                    ->dehydrated(false)
                    ->visibleOn('create')
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state === null) {
                            return;
                        }

                        $preset = ScheduleService::presets()[$state];
                        $set('name', __('timeclock.schedules.presets.'.$state));
                        $set('type', $preset['type']);

                        foreach (['week', 'work_days', 'off_days', 'start_time', 'end_time', 'break_minutes'] as $field) {
                            if (array_key_exists($field, $preset)) {
                                $set($field, $preset[$field]);
                            }
                        }

                        // The model's week opens grouped (e.g. Monday to Friday together, Saturday apart).
                        $group = static::groupOf((array) ($preset['week'] ?? []));
                        $set('same_days', $group['days']);
                        foreach ($group['hours'] as $part => $value) {
                            $set('group_'.$part, $value);
                        }

                        if ($preset['type'] === Schedule::CYCLE) {
                            $set('anchor_date', today()->toDateString());
                        }
                    }),
                Grid::make(['default' => 1, 'sm' => 2])->components([
                    TextInput::make('name')
                        ->label(__('timeclock.fields.name'))
                        ->required()
                        ->maxLength(120),
                    ToggleButtons::make('type')
                        ->label(__('timeclock.schedules.type'))
                        ->options([Schedule::WEEKLY => __('timeclock.schedules.types.weekly'), Schedule::CYCLE => __('timeclock.schedules.types.cycle')])
                        ->default(Schedule::WEEKLY)
                        ->inline()
                        ->required()
                        ->live(),
                ]),
                Section::make(__('timeclock.schedules.week'))
                    ->description(__('timeclock.schedules.week_help'))
                    ->visible($isWeekly)
                    ->components([
                        // 1. The days that share the same hours (e.g. Monday to Friday): one row of hours for all of them.
                        CheckboxList::make('same_days')
                            ->label(__('timeclock.schedules.same_days'))
                            ->helperText(__('timeclock.schedules.same_days_help'))
                            ->options(collect(range(1, 7))->mapWithKeys(fn (int $weekday): array => [$weekday => __('timeclock.weekdays_short.'.$weekday)])->all())
                            ->columns(['default' => 4, 'sm' => 7])
                            ->default([1, 2, 3, 4, 5])
                            ->dehydrated(false)
                            ->afterStateHydrated(fn (CheckboxList $component, Get $get) => filled($get('week')) ? $component->state(static::groupOf((array) $get('week'))['days']) : null)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::syncGroup($get, $set)),
                        Grid::make(['default' => 2, 'md' => 5])
                            ->visible(fn (Get $get): bool => filled($get('same_days')))
                            ->components([
                                Text::make(fn (Get $get): string => static::daysLabel((array) $get('same_days')))->weight('bold'),
                                ...collect(['start', 'break_start', 'break_end', 'end'])->map(fn (string $part) => static::time('group_'.$part)
                                    ->label(__('timeclock.schedules.'.$part))
                                    ->dehydrated(false)
                                    ->required(in_array($part, ['start', 'end'], true))
                                    ->afterStateHydrated(fn (TextInput $component, Get $get) => filled($get('week')) ? $component->state(static::groupOf((array) $get('week'))['hours'][$part] ?? null) : null)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => static::syncGroup($get, $set)))->all(),
                            ]),
                        // 2. Every other day on its own row: off unless switched on.
                        ...collect(range(1, 7))->map(fn (int $weekday) => static::dayFields($weekday))->all(),
                    ]),
                Section::make(__('timeclock.schedules.cycle'))
                    ->description(__('timeclock.schedules.cycle_help'))
                    ->visible(fn (Get $get): bool => ! $isWeekly($get))
                    ->columns(['default' => 2, 'md' => 3])
                    ->components([
                        TextInput::make('work_days')->label(__('timeclock.schedules.work_days'))->integer()->minValue(1)->maxValue(30),
                        TextInput::make('off_days')->label(__('timeclock.schedules.off_days'))->integer()->minValue(0)->maxValue(30),
                        DatePicker::make('anchor_date')->label(__('timeclock.schedules.anchor_date'))->helperText(__('timeclock.schedules.anchor_date_help')),
                        static::time('start_time')->label(__('timeclock.schedules.start')),
                        static::time('end_time')->label(__('timeclock.schedules.end')),
                        TextInput::make('break_minutes')->label(__('timeclock.schedules.break_minutes'))->integer()->minValue(0)->maxValue(240)->suffix('min'),
                    ]),
                Text::make(fn (Get $get): string => __('timeclock.schedules.weekly_total', ['hours' => ScheduleCalendar::hours(app(ScheduleCalendar::class)->weeklyMinutes(new Schedule([
                    'type' => $get('type'), 'week' => $get('week'), 'work_days' => (int) $get('work_days'), 'off_days' => (int) $get('off_days'),
                    'start_time' => $get('start_time') ?: '00:00', 'end_time' => $get('end_time') ?: '00:00', 'break_minutes' => (int) $get('break_minutes'),
                    'anchor_date' => $get('anchor_date') ?: today()->toDateString(),
                ])))])),
            ]);
    }

    /**
     * One weekday outside the group: works? (off = day off) and its own hours. The days of the group are hidden
     * here but still saved, with the group's hours.
     */
    public static function dayFields(int $weekday): Grid
    {
        $inGroup = fn (Get $get): bool => in_array($weekday, array_map('intval', (array) $get('same_days')), true);
        $works = fn (Get $get): bool => (bool) $get("week.{$weekday}.works");

        // Each field is hidden on its own (a hidden container would not save its fields).
        return Grid::make(['default' => 2, 'md' => 5])
            ->components([
                Toggle::make("week.{$weekday}.works")
                    ->label(__('timeclock.weekdays.'.$weekday))
                    ->helperText(fn (Get $get): ?string => $works($get) ? null : __('timeclock.schedules.day_off'))
                    ->inline(false)
                    ->live()
                    ->hidden($inGroup)
                    ->dehydratedWhenHidden()
                    // On save, a day of the group is a working day whatever the screen showed.
                    ->dehydrateStateUsing(fn ($state, Get $get): bool => $inGroup($get) || (bool) $state),
                ...collect(['start', 'break_start', 'break_end', 'end'])->map(fn (string $part) => static::time("week.{$weekday}.{$part}")
                    ->label(__('timeclock.schedules.'.$part))
                    ->visible(fn (Get $get): bool => $works($get) && ! $inGroup($get))
                    ->required(fn (Get $get): bool => in_array($part, ['start', 'end'], true) && $works($get) && ! $inGroup($get))
                    ->dehydratedWhenHidden()
                    // ...with the group's hours.
                    ->dehydrateStateUsing(fn ($state, Get $get) => $inGroup($get) ? $get('group_'.$part) : $state))->all(),
            ]);
    }

    /**
     * The biggest set of working days with the very same hours (an existing schedule opens grouped that way).
     *
     * @return array{days: array<int, int>, hours: array<string, ?string>}
     */
    public static function groupOf(array $week): array
    {
        $groups = [];

        foreach (range(1, 7) as $weekday) {
            if (! ($week[$weekday]['works'] ?? false)) {
                continue;
            }

            $hours = [];
            foreach (['start', 'break_start', 'break_end', 'end'] as $part) {
                $hours[$part] = filled($week[$weekday][$part] ?? null) ? substr((string) $week[$weekday][$part], 0, 5) : null;
            }

            $groups[json_encode($hours)]['hours'] = $hours;
            $groups[json_encode($hours)]['days'][] = $weekday;
        }

        return collect($groups)->sortByDesc(fn (array $group): int => count($group['days']))->first() ?? ['days' => [], 'hours' => []];
    }

    /** The group's days get its hours and are working days. */
    protected static function syncGroup(Get $get, Set $set): void
    {
        foreach (array_map('intval', (array) $get('same_days')) as $weekday) {
            $set("week.{$weekday}.works", true);

            foreach (['start', 'break_start', 'break_end', 'end'] as $part) {
                $set("week.{$weekday}.{$part}", $get('group_'.$part));
            }
        }
    }

    /** "Mon to Fri", "Mon, Wed and Fri"... */
    public static function daysLabel(array $days): string
    {
        $days = collect($days)->map(fn ($day): int => (int) $day)->sort()->values();

        if ($days->isEmpty()) {
            return '';
        }

        $consecutive = $days->count() > 2 && $days->last() - $days->first() === $days->count() - 1;

        if ($consecutive) {
            return __('timeclock.schedules.days_range', ['from' => __('timeclock.weekdays_short.'.$days->first()), 'to' => __('timeclock.weekdays_short.'.$days->last())]);
        }

        $names = $days->map(fn (int $day): string => __('timeclock.weekdays_short.'.$day));

        return $names->count() === 1 ? $names->first() : $names->slice(0, -1)->implode(', ').' '.__('timeclock.schedules.days_and').' '.$names->last();
    }

    protected static function time(string $field): TextInput
    {
        return TimeInput::make($field);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('timeclock.fields.name'))
                    ->weight('bold')
                    ->description(fn (Schedule $record): string => app(ScheduleCalendar::class)->summary($record))
                    ->searchable(),
                TextColumn::make('weekly')
                    ->label(__('timeclock.schedules.weekly_hours'))
                    ->state(fn (Schedule $record): string => ScheduleCalendar::hours(app(ScheduleCalendar::class)->weeklyMinutes($record)))
                    ->alignEnd(),
                ToggleColumn::make('is_active')
                    ->label(__('timeclock.fields.active')),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth('5xl')
                    ->visible(fn (Schedule $record): bool => ! $record->isInUse())
                    ->using(function (Schedule $record, array $data, EditAction $action): Schedule {
                        static::checkOrHalt($data, $action);
                        $record->update($data);

                        return $record;
                    }),
                Action::make('duplicate')
                    ->label(__('timeclock.schedules.duplicate'))
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->link()
                    ->authorize('create')
                    ->tooltip(fn (Schedule $record): ?string => $record->isInUse() ? __('timeclock.schedules.in_use_help') : null)
                    ->action(function (Schedule $record): void {
                        $copy = app(ScheduleService::class)->duplicate($record);
                        Notification::make()->success()->title(__('timeclock.schedules.duplicated', ['name' => $copy->name]))->send();
                    }),
                DeleteAction::make()
                    ->visible(fn (Schedule $record): bool => ! $record->isInUse()),
            ])
            ->emptyStateHeading(__('timeclock.schedules.empty'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function checkOrHalt(array $data, Action $action): void
    {
        try {
            app(ScheduleService::class)->check($data);
        } catch (PersonRuleException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            $action->halt();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSchedules::route('/'),
        ];
    }
}
