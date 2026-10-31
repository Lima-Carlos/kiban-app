<?php

namespace App\Modules\Fleet\Filament\Requests;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\Enums\RequestStatus;
use App\Modules\Fleet\Enums\ServiceKind;
use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Filament\RunsFleetRules;
use App\Modules\Fleet\Filament\WorkForms;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\ServiceRequest;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\MaintenanceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Requests for maintenance or wash. Drivers ask (and follow theirs); managers and mechanics schedule or reject.
 */
class ServiceRequestResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = ServiceRequest::class;

    protected static ?string $langGroup = 'fleet.requests';

    protected static ?string $slug = 'frota/solicitacoes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('fleet.requests.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.requests.plural');
    }

    /** Menu badge: how many are waiting. */
    public static function getNavigationBadge(): ?string
    {
        $open = static::getEloquentQuery()->where('status', RequestStatus::Open->value)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['vehicle', 'requester', 'appointment']);
        $visible = app(FleetAccess::class)->visibleVehicleIds();

        return $visible === null
            ? $query
            : $query->where(fn (Builder $query) => $query->whereIn('vehicle_id', $visible)->orWhere('requested_by', Auth::id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('fleet.fields.date'))
                    ->dateTime(__('app.datetime_format'))
                    ->sortable(),
                TextColumn::make('vehicle.plate')
                    ->label(__('fleet.fields.vehicle'))
                    ->weight('bold')
                    ->description(fn (ServiceRequest $record): ?string => $record->requester?->name),
                TextColumn::make('kind')
                    ->label(__('fleet.fields.service_kind'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('description')
                    ->label(__('fleet.fields.what_is_needed'))
                    ->limit(60)
                    ->wrap()
                    ->description(fn (ServiceRequest $record): ?string => $record->response),
                IconColumn::make('photo_path')
                    ->label('')
                    ->state(fn (ServiceRequest $record): bool => filled($record->photo_path))
                    ->icon(fn (bool $state): ?Heroicon => $state ? Heroicon::OutlinedPhoto : null)
                    ->visibleFrom('md'),
                TextColumn::make('status')
                    ->label(__('fleet.fields.status'))
                    ->badge()
                    ->description(fn (ServiceRequest $record): ?string => $record->appointment?->scheduled_at?->timezone(FleetFields::timezone())->translatedFormat(__('app.datetime_format'))),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('fleet.fields.status'))
                    ->options(RequestStatus::class),
            ])
            ->recordActions([
                Action::make('photo')
                    ->label(__('fleet.actions.open_photo'))
                    ->icon(Heroicon::OutlinedPhoto)
                    ->link()
                    ->visible(fn (ServiceRequest $record): bool => filled($record->photo_path))
                    ->url(fn (ServiceRequest $record): string => Storage::disk('local')->temporaryUrl($record->photo_path, now()->addMinutes(10)))
                    ->openUrlInNewTab(),
                Action::make('schedule')
                    ->label(__('fleet.actions.schedule'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->color('success')
                    ->visible(fn (ServiceRequest $record): bool => $record->status === RequestStatus::Open)
                    ->authorize('update')
                    ->schema(WorkForms::schedule(false))
                    ->action(fn (ServiceRequest $record, array $data) => RunsFleetRules::attempt(fn () => app(MaintenanceService::class)->scheduleRequest($record, [
                        'scheduled_at' => WorkForms::date($data['scheduled_at']),
                        'workshop_id' => $data['workshop_id'] ?? null,
                        'location' => $data['location'] ?? null,
                        'assigned_to' => $data['assigned_to'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ], auth()->user()))),
                Action::make('reject')
                    ->label(__('fleet.actions.reject'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (ServiceRequest $record): bool => $record->status === RequestStatus::Open)
                    ->authorize('update')
                    ->schema([
                        Textarea::make('response')->label(__('fleet.fields.response'))->required(),
                    ])
                    ->action(fn (ServiceRequest $record, array $data) => RunsFleetRules::attempt(fn () => app(MaintenanceService::class)->rejectRequest($record, $data['response'], auth()->user()))),
            ])
            ->emptyStateHeading(__('fleet.requests.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageServiceRequests::route('/'),
        ];
    }

    /**
     * The "request maintenance or wash" form (driver, phone-first).
     *
     * @return array<int, mixed>
     */
    public static function requestForm(): array
    {
        return [
            WorkForms::vehicleSelect(),
            ToggleButtons::make('kind')
                ->label(__('fleet.fields.service_kind'))
                ->options(ServiceKind::class)
                ->default(ServiceKind::Maintenance->value)
                ->inline()
                ->required(),
            Textarea::make('description')
                ->label(__('fleet.fields.what_is_needed'))
                ->placeholder(__('fleet.helpers.what_is_needed'))
                ->rows(3)
                ->required(),
            FleetFields::photo('photo_path', 'requests')
                ->label(__('fleet.fields.photo_optional')),
        ];
    }
}
