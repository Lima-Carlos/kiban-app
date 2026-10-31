<?php

namespace App\Modules\TimeClock\Filament\Employees;

use App\Filament\Pages\PeoplePage;
use App\Filament\Support\ModuleResource;
use App\Models\Team;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\KioskService;
use App\Modules\TimeClock\Services\PhotoNoticeService;
use App\Modules\TimeClock\Services\ScheduleCalendar;
use App\Modules\TimeClock\Services\TimeClockPeople;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PersonRuleException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Employees of the time clock (managers and viewers). People are added in the "People" screen;
 * here the manager sees the list by team and workplace and adjusts the time clock data.
 */
class EmployeeResource extends ModuleResource
{
    protected static string $moduleKey = TimeClockModule::KEY;

    protected static ?string $model = Employee::class;

    protected static ?string $langGroup = 'timeclock.employees';

    protected static ?string $slug = 'ponto/funcionarios';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('timeclock.employees.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('timeclock.employees.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('timeclock.employees.plural_title');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['person.team', 'workplace']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('admitted_on', 'desc')
            ->columns([
                TextColumn::make('person.name')
                    ->label(__('timeclock.fields.name'))
                    ->weight('bold')
                    ->description(fn (Employee $record): ?string => $record->job_title)
                    ->searchable(),
                TextColumn::make('person.team.name')
                    ->label(__('app.teams.team'))
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('workplace.name')
                    ->label(__('timeclock.fields.workplace'))
                    ->state(fn (Employee $record): ?string => $record->is_external ? __('timeclock.fields.external_short') : $record->workplace?->name)
                    ->badge()
                    ->color(fn (Employee $record): string => $record->is_external ? 'warning' : 'gray'),
                TextColumn::make('schedule')
                    ->label(__('timeclock.fields.schedule'))
                    ->state(fn (Employee $record): ?string => app(ScheduleCalendar::class)->scheduleOn($record, today())?->name)
                    ->placeholder(__('timeclock.employees.no_schedule'))
                    ->color(fn (?string $state): ?string => $state ? null : 'danger')
                    ->visibleFrom('md'),
                TextColumn::make('admitted_on')
                    ->label(__('timeclock.fields.admitted_on'))
                    ->date(__('app.date_format'))
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('photo_notice')
                    ->label(__('timeclock.privacy.column'))
                    ->state(fn (Employee $record): string => app(PhotoNoticeService::class)->status($record, FilamentTimezone::get()))
                    ->color(fn (Employee $record): ?string => app(PhotoNoticeService::class)->acknowledged($record) ? null : 'warning')
                    ->size('sm')
                    ->wrap()
                    ->visibleFrom('lg'),
                IconColumn::make('login')
                    ->label(__('timeclock.fields.login'))
                    ->state(fn (Employee $record): bool => $record->person?->hasLogin() ?? false)
                    ->boolean()
                    ->visibleFrom('lg'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('app.people.situation'))
                    ->trueLabel(__('app.people.active_only'))
                    ->falseLabel(__('app.people.inactive_only'))
                    ->default(true),
                SelectFilter::make('team')
                    ->label(__('app.teams.team'))
                    ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereHas('person', fn (Builder $query) => $query->where('team_id', $data['value'])) : $query),
                SelectFilter::make('workplace_id')
                    ->label(__('timeclock.fields.workplace'))
                    ->options(fn (): array => Workplace::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('month')
                    ->label(__('timeclock.employees.see_month'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->link()
                    ->modalHeading(fn (Employee $record): string => __('timeclock.employees.month_title', ['name' => $record->person->name, 'month' => ucfirst(now()->translatedFormat('F Y'))]))
                    ->modalContent(fn (Employee $record) => view('timeclock.employee-month', [
                        'days' => app(ScheduleCalendar::class)->period($record, now()->startOfMonth(), now()->endOfMonth()),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('timeclock.employees.close')),
                Action::make('pin')
                    ->label(fn (Employee $record): string => $record->hasPin() ? __('timeclock.employees.new_pin') : __('timeclock.employees.create_pin'))
                    ->icon(Heroicon::OutlinedKey)
                    ->link()
                    ->authorize('update')
                    ->visible(fn (Employee $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Employee $record): string => $record->hasPin() ? __('timeclock.employees.new_pin_help') : __('timeclock.employees.create_pin_help'))
                    ->action(function (Employee $record): void {
                        $pin = app(KioskService::class)->newPin($record);
                        Notification::make()
                            ->success()
                            ->persistent()
                            ->title(__('timeclock.employees.pin_ready', ['name' => $record->person->name]))
                            ->body(__('timeclock.employees.pin_body', ['pin' => $pin]))
                            ->send();
                    }),
                Action::make('edit')
                    ->label(__('app.people.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->link()
                    ->authorize('update')
                    ->visible(fn (Employee $record): bool => $record->is_active)
                    ->modalWidth('2xl')
                    ->fillForm(fn (Employee $record): array => app(TimeClockPeople::class)->data($record->person) + ['with_login' => $record->person->hasLogin()])
                    ->schema(fn (): array => app(TimeClockPeople::class)->fields())
                    ->action(function (Employee $record, array $data, Action $action): void {
                        try {
                            DB::transaction(fn () => app(TimeClockPeople::class)->save($record->person, $data + ['role' => app(TimeClockPeople::class)->data($record->person)['role']]));
                        } catch (PersonRuleException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                            $action->halt();
                        }

                        Notification::make()->success()->title(__('app.people.saved'))->send();
                    }),
            ])
            ->emptyStateHeading(__('timeclock.employees.empty'))
            ->emptyStateDescription(__('timeclock.employees.empty_help'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
        ];
    }

    /** "Add employee" opens the People form (the same person in every system). */
    public static function addUrl(): string
    {
        return PeoplePage::getUrl(['add' => 1]);
    }
}
