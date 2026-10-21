<?php

namespace App\Modules\Fleet\Filament\Appointments;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\Enums\AppointmentStatus;
use App\Modules\Fleet\Enums\ServiceKind;
use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Filament\RunsFleetRules;
use App\Modules\Fleet\Filament\WorkForms;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Appointment;
use App\Modules\Fleet\Services\MaintenanceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Appointments (maintenance and wash). "Finish" records the maintenance or the wash; the calendar shows the month.
 */
class AppointmentResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = Appointment::class;

    protected static ?string $slug = 'frota/agenda';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('fleet.appointments.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.appointments.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.appointments.title');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vehicle', 'workshop', 'assignee', 'request']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at')
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label(__('fleet.fields.scheduled_at'))
                    ->dateTime(__('app.datetime_format'))
                    ->color(fn (Appointment $record): ?string => $record->isOpen() && $record->scheduled_at->isPast() ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('vehicle.plate')
                    ->label(__('fleet.fields.vehicle'))
                    ->weight('bold')
                    ->description(fn (Appointment $record): string => $record->vehicle->brand.' '.$record->vehicle->model),
                TextColumn::make('kind')
                    ->label(__('fleet.fields.service_kind'))
                    ->badge()
                    ->color(fn (Appointment $record): string => $record->kind === ServiceKind::Wash ? 'info' : 'warning'),
                TextColumn::make('place')
                    ->label(__('fleet.fields.place'))
                    ->state(fn (Appointment $record): ?string => $record->place())
                    ->description(fn (Appointment $record): ?string => $record->assignee?->name)
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('notes')
                    ->label(__('fleet.fields.notes'))
                    ->limit(50)
                    ->placeholder('—')
                    ->visibleFrom('lg'),
                TextColumn::make('status')
                    ->label(__('fleet.fields.status'))
                    ->badge(),
            ])
            ->filters([
                TernaryFilter::make('open')
                    ->label(__('fleet.filters.open_appointments'))
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->where('status', AppointmentStatus::Scheduled->value),
                        false: fn (Builder $query) => $query->where('status', '!=', AppointmentStatus::Scheduled->value),
                    ),
                SelectFilter::make('kind')
                    ->label(__('fleet.fields.service_kind'))
                    ->options(ServiceKind::class),
            ])
            ->recordActions([
                static::finishMaintenanceAction(),
                static::finishWashAction(),
                Action::make('cancel')
                    ->label(__('fleet.actions.cancel_appointment'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->iconButton()
                    ->visible(fn (Appointment $record): bool => $record->isOpen())
                    ->authorize('update')
                    ->schema([Textarea::make('reason')->label(__('fleet.fields.reason'))->required()])
                    ->action(fn (Appointment $record, array $data) => RunsFleetRules::attempt(fn () => app(MaintenanceService::class)->cancel($record, $data['reason'], auth()->user()))),
            ])
            ->emptyStateHeading(__('fleet.appointments.empty'));
    }

    public static function finishMaintenanceAction(): Action
    {
        return Action::make('finishMaintenance')
            ->label(__('fleet.actions.finish'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Appointment $record): bool => $record->isOpen() && $record->kind === ServiceKind::Maintenance)
            ->authorize('update')
            ->modalHeading(__('fleet.maintenances.record'))
            ->modalWidth('3xl')
            ->fillForm(fn (Appointment $record): array => [
                'odometer' => $record->vehicle->odometer,
                'performed_on' => now()->toDateString(),
                'workshop_id' => $record->workshop_id,
                'performed_by' => $record->assigned_to,
                'description' => $record->notes,
            ])
            ->schema(WorkForms::maintenance(false))
            ->action(fn (Appointment $record, array $data) => RunsFleetRules::attempt(
                fn () => app(MaintenanceService::class)->recordMaintenance($record->vehicle, static::maintenanceData($data), auth()->user(), $record),
                __('fleet.maintenances.recorded'),
            ));
    }

    public static function finishWashAction(): Action
    {
        return Action::make('finishWash')
            ->label(__('fleet.actions.finish'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Appointment $record): bool => $record->isOpen() && $record->kind === ServiceKind::Wash)
            ->authorize('update')
            ->modalHeading(__('fleet.washes.record'))
            ->fillForm(fn (Appointment $record): array => ['washed_on' => now()->toDateString(), 'place' => $record->place()])
            ->schema(WorkForms::wash(false))
            ->action(fn (Appointment $record, array $data) => RunsFleetRules::attempt(
                fn () => app(MaintenanceService::class)->recordWash($record->vehicle, static::washData($data), auth()->user(), $record),
            ));
    }

    /**
     * Form values → MaintenanceService data (money in cents, dates).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function maintenanceData(array $data): array
    {
        return [
            'type' => $data['type'] instanceof BackedEnum ? $data['type']->value : $data['type'],
            'services' => array_map(fn ($item): string => $item instanceof BackedEnum ? $item->value : (string) $item, (array) $data['services']),
            'performed_on' => WorkForms::date($data['performed_on']),
            'odometer' => (int) $data['odometer'],
            'description' => $data['description'] ?? null,
            'workshop_id' => $data['workshop_id'] ?? null,
            'performed_by' => $data['performed_by'] ?? null,
            'parts_cents' => WorkForms::cents($data['parts'] ?? 0),
            'labor_cents' => WorkForms::cents($data['labor'] ?? 0),
            'invoice_path' => $data['invoice_path'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function washData(array $data): array
    {
        return [
            'kind' => $data['kind'] instanceof BackedEnum ? $data['kind']->value : $data['kind'],
            'washed_on' => WorkForms::date($data['washed_on']),
            'odometer' => filled($data['odometer'] ?? null) ? (int) $data['odometer'] : null,
            'cost_cents' => WorkForms::cents($data['cost'] ?? 0),
            'place' => $data['place'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAppointments::route('/'),
            'calendar' => Pages\AppointmentCalendar::route('/calendario'),
        ];
    }

    public static function timezone(): string
    {
        return FleetFields::timezone();
    }
}
