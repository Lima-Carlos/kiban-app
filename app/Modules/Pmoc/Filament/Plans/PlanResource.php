<?php

namespace App\Modules\Pmoc\Filament\Plans;

use App\Filament\Support\ModuleResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\Site;
use App\Modules\Pmoc\Models\Equipment;
use App\Modules\Pmoc\Models\Plan;
use App\Modules\Pmoc\PmocModule;
use App\Modules\Pmoc\Services\PmocService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Maintenance plans (PMOC): the customer's address, the responsible technician, the frequency of the visits and
 * the equipment list. Actions: open the next visit now and download the PMOC document.
 */
class PlanResource extends ModuleResource
{
    protected static string $moduleKey = PmocModule::KEY;

    protected static ?string $model = Plan::class;

    protected static ?string $langGroup = 'pmoc.plans';

    protected static ?string $slug = 'pmoc/planos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('pmoc.plans.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pmoc.plans.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'site'])->withCount(['equipment' => fn (Builder $query) => $query->where('is_active', true)]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pmoc.plans.where'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        Select::make('customer_id')
                            ->label(__('pmoc.fields.customer'))
                            ->options(fn (): array => Customer::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Customer $customer): array => [$customer->getKey() => $customer->displayName()])->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('site_id', null)),
                        Select::make('site_id')
                            ->label(__('pmoc.fields.site'))
                            ->helperText(__('pmoc.helpers.site'))
                            ->options(fn (Get $get): array => Site::query()->where('customer_id', $get('customer_id'))->get()->mapWithKeys(fn (Site $site): array => [$site->getKey() => $site->address() ?: '#'.$site->getKey()])->all()),
                        TextInput::make('name')
                            ->label(__('pmoc.fields.name'))
                            ->placeholder(__('pmoc.helpers.name'))
                            ->required()
                            ->maxLength(160)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pmoc.plans.responsible'))
                    ->description(__('pmoc.plans.responsible_help'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        TextInput::make('responsible_name')->label(__('pmoc.fields.responsible_name'))->maxLength(160),
                        TextInput::make('responsible_registration')->label(__('pmoc.fields.responsible_registration'))->helperText(__('pmoc.helpers.registration'))->maxLength(60),
                        TextInput::make('area_m2')->label(__('pmoc.fields.area_m2'))->numeric()->integer()->minValue(0)->suffix('m²'),
                        TextInput::make('occupants')->label(__('pmoc.fields.occupants'))->numeric()->integer()->minValue(0),
                    ]),
                Section::make(__('pmoc.plans.visits'))
                    ->description(__('pmoc.plans.visits_help'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components([
                        Select::make('frequency_months')
                            ->label(__('pmoc.fields.frequency'))
                            ->options(collect(Plan::FREQUENCIES)->mapWithKeys(fn (int $months): array => [$months => trans_choice('pmoc.frequency', $months, ['count' => $months])])->all())
                            ->default(1)
                            ->required(),
                        DatePicker::make('starts_on')
                            ->label(__('pmoc.fields.starts_on'))
                            ->helperText(__('pmoc.helpers.starts_on'))
                            ->default(now()->toDateString())
                            ->required(),
                        TextInput::make('lead_days')
                            ->label(__('pmoc.fields.lead_days'))
                            ->helperText(__('pmoc.helpers.lead_days'))
                            ->numeric()->integer()->minValue(0)->maxValue(60)->default(7)->suffix(__('pmoc.fields.days'))
                            ->required(),
                        Select::make('service_type_id')
                            ->label(__('pmoc.fields.service_type'))
                            ->placeholder(__('pmoc.service_type'))
                            ->helperText(__('pmoc.helpers.service_type'))
                            ->options(fn (): array => ServiceType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pmoc.plans.equipment'))
                    ->description(__('pmoc.plans.equipment_help'))
                    ->components([
                        Repeater::make('equipment')
                            ->hiddenLabel()
                            ->relationship('equipment')
                            ->defaultItems(1)
                            ->addActionLabel(__('pmoc.equipment.add'))
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => collect([$state['tag'] ?? null, $state['room'] ?? null])->filter()->implode(' · ') ?: null)
                            ->schema([
                                Grid::make(['default' => 1, 'sm' => 3])->components([
                                    TextInput::make('tag')->label(__('pmoc.fields.tag'))->placeholder(__('pmoc.helpers.tag'))->required()->maxLength(80),
                                    Select::make('kind')->label(__('pmoc.fields.kind'))
                                        ->options(collect(Equipment::KINDS)->mapWithKeys(fn (string $kind): array => [$kind => __('pmoc.kinds.'.$kind)])->all())
                                        ->default('split')->required(),
                                    TextInput::make('room')->label(__('pmoc.fields.room'))->maxLength(120),
                                    TextInput::make('brand')->label(__('pmoc.fields.brand'))->maxLength(80),
                                    TextInput::make('model')->label(__('pmoc.fields.model'))->maxLength(80),
                                    TextInput::make('serial')->label(__('pmoc.fields.serial'))->maxLength(80),
                                    TextInput::make('capacity_btu')->label(__('pmoc.fields.capacity_btu'))->numeric()->integer()->minValue(0)->suffix('BTU/h'),
                                    Select::make('refrigerant')->label(__('pmoc.fields.refrigerant'))
                                        ->options(collect(Equipment::REFRIGERANTS)->mapWithKeys(fn (string $gas): array => [$gas => $gas === 'other' ? __('pmoc.other') : $gas])->all()),
                                    DatePicker::make('installed_on')->label(__('pmoc.fields.installed_on')),
                                    Toggle::make('is_active')->label(__('pmoc.fields.active'))->default(true)->inline(false),
                                ]),
                            ]),
                    ]),
                Textarea::make('notes')->label(__('pmoc.fields.notes'))->rows(2),
                Toggle::make('is_active')->label(__('pmoc.fields.plan_active'))->helperText(__('pmoc.helpers.plan_active'))->default(true),
                View::make('pmoc.visits')
                    ->visibleOn('edit')
                    ->viewData(fn (?Plan $record): array => ['visits' => $record?->visits()->with('workOrder')->limit(12)->get() ?? collect()]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('next_due_on')
            ->columns([
                TextColumn::make('name')->label(__('pmoc.fields.name'))->weight('bold')->searchable()
                    ->description(fn (Plan $record): string => $record->customer?->displayName() ?? ''),
                TextColumn::make('equipment_count')->label(__('pmoc.plans.equipment_count')),
                TextColumn::make('frequency_months')->label(__('pmoc.fields.frequency'))
                    ->formatStateUsing(fn (int $state): string => trans_choice('pmoc.frequency', $state, ['count' => $state])),
                TextColumn::make('next_due_on')->label(__('pmoc.fields.next_due_on'))->date(__('app.date_format'))->sortable(),
                IconColumn::make('is_active')->label(__('pmoc.fields.active'))->boolean(),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label(__('pmoc.document.download'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('gray')
                    ->authorize('view')
                    ->action(fn (Plan $record): StreamedResponse => response()->streamDownload(fn () => print (app(PmocService::class)->pdf($record)), app(PmocService::class)->fileName($record), ['Content-Type' => 'application/pdf'])),
                Action::make('labels')
                    ->label(__('pmoc.labels.print'))
                    ->icon(Heroicon::OutlinedQrCode)
                    ->color('gray')
                    ->authorize('view')
                    ->url(fn (Plan $record): string => route('labels.pmoc', ['plan' => $record->getKey()]))
                    ->openUrlInNewTab(),
                Action::make('openVisit')
                    ->label(__('pmoc.visit.open_now'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->color('gray')
                    ->authorize('update')
                    ->visible(fn (Plan $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Plan $record): string => __('pmoc.visit.open_now_help', ['date' => $record->next_due_on->translatedFormat(__('app.date_format'))]))
                    ->action(function (Plan $record): void {
                        $visit = app(PmocService::class)->openVisit($record);
                        Notification::make()->success()->title(__('pmoc.visit.opened', ['number' => $visit->workOrder?->label()]))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('pmoc.plans.empty'))
            ->emptyStateDescription(__('pmoc.plans.empty_help'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/novo'),
            'edit' => Pages\EditPlan::route('/{record}/editar'),
        ];
    }
}
