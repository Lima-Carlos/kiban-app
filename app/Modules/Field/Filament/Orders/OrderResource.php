<?php

namespace App\Modules\Field\Filament\Orders;

use App\Connections\Bridges\FieldEpi;
use App\Connections\Bridges\FieldFleet;
use App\Filament\Support\ModuleResource;
use App\Modules\Field\Enums\OrderPriority;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\OrderEvent;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\Field\Services\FieldFormat;
use App\Modules\Field\Services\FieldReports;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Services\FleetFormat;
use App\People\Documents\PersonDocumentService;
use App\Support\Country;
use App\Support\Geo;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Work orders (manager and service desk): open an order for a customer's address with a type of service, put it
 * on a technician's agenda, reschedule, take off the agenda, cancel with the reason, write notes. Each order shows
 * its whole history. The agenda of the day (with the map) is AgendaPage.
 */
class OrderResource extends ModuleResource
{
    protected static string $moduleKey = FieldModule::KEY;

    protected static ?string $model = WorkOrder::class;

    protected static ?string $langGroup = 'field.orders';

    protected static ?string $slug = 'campo/ordens';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('field.orders.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('field.orders.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('field.orders.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('field.orders.singular_title');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = WorkOrder::query()->where('status', OrderStatus::Open->value)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('field.orders.badge');
    }

    /** The company's time zone (also outside a page, e.g. in routines and reports). */
    public static function timezone(): string
    {
        return app(TenantContext::class)->current()?->license()->tenant('timezone') ?: FilamentTimezone::get();
    }

    /**
     * @return array<int|string, string>
     */
    public static function technicianOptions(): array
    {
        return app(FieldAccess::class)->technicians()->mapWithKeys(fn ($user): array => [$user->getKey() => $user->name])->all();
    }

    /**
     * For an existing order: the nearest technicians first, free ones before busy ones
     * ("Ana Souza · free · about 10 min away").
     *
     * @return array<int|string, string>
     */
    public static function nearestOptions(WorkOrder $order): array
    {
        $expired = static::expiredDocuments();

        return collect(app(FieldReports::class)->nearest($order))
            ->mapWithKeys(fn (array $row): array => [$row['technician']->getKey() => implode(' · ', array_filter([
                $row['technician']->name,
                $row['free'] ? __('field.orders.nearest_free') : __('field.orders.nearest_busy'),
                $row['travel'] !== null ? __('field.orders.nearest_travel', ['minutes' => $row['travel']]) : null,
                isset($expired[$row['technician']->getKey()]) ? '⚠ '.$expired[$row['technician']->getKey()] : null,
            ]))])
            ->all();
    }

    /**
     * Technicians to choose when scheduling, with an expired safety training shown next to the name (warns, never forbids).
     *
     * @return array<int|string, string>
     */
    public static function technicianChoices(): array
    {
        $expired = static::expiredDocuments();

        return collect(static::technicianOptions())
            ->map(fn (string $name, int|string $id): string => isset($expired[$id]) ? $name.' · ⚠ '.$expired[$id] : $name)
            ->all();
    }

    /**
     * Technicians (by login) with an expired document of the Campo (e.g. NR-10, NR-35): "NR-10 vencida em 10/03/2026".
     *
     * @return array<int, string>
     */
    public static function expiredDocuments(): array
    {
        return app(PersonDocumentService::class)->expiredTexts(FieldModule::KEY);
    }

    /** "Atenção: NR-10 vencida em 10/03/2026" for the chosen technician, or null. */
    public static function technicianWarning(mixed $technicianId): ?string
    {
        if (blank($technicianId)) {
            return null;
        }

        $expired = app(PersonDocumentService::class)->expiredTexts(FieldModule::KEY, [(int) $technicianId]);

        return isset($expired[(int) $technicianId]) ? __('documents.attention.technician_hint', ['documents' => $expired[(int) $technicianId]]) : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('field.orders.sections.what'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        Select::make('customer_id')
                            ->label(__('field.fields.customer'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => static::searchCustomers($search))
                            ->getOptionLabelUsing(fn ($value): ?string => Customer::query()->find($value)?->displayName())
                            ->helperText(fn (): string => __('field.helpers.order_customer'))
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => $set('site_id', Customer::query()->find($state)?->mainSite?->getKey()))
                            ->required()
                            ->validationMessages(['required' => __('field.validation.customer')])
                            ->disabledOn('edit')
                            ->columnSpanFull(),
                        Select::make('site_id')
                            ->label(__('field.fields.site'))
                            ->options(fn (Get $get): array => Site::query()->where('customer_id', $get('customer_id'))->orderByDesc('is_main')->get()
                                ->mapWithKeys(fn (Site $site): array => [$site->getKey() => collect([$site->label, $site->address()])->filter()->implode(' · ') ?: __('field.sites.default_label')])->all())
                            ->visible(fn (Get $get): bool => filled($get('customer_id')))
                            ->columnSpanFull(),
                        Select::make('service_type_id')
                            ->label(__('field.fields.service_type'))
                            ->options(fn (): array => ServiceType::query()->active()->orderBy('sort')->pluck('name', 'id')->all())
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => $set('duration_minutes', ServiceType::query()->find($state)?->duration_minutes))
                            ->required()
                            ->validationMessages(['required' => __('field.validation.service_type')])
                            ->disabledOn('edit'),
                        ToggleButtons::make('priority')
                            ->label(__('field.fields.priority'))
                            ->options(OrderPriority::class)
                            ->default(OrderPriority::Normal->value)
                            ->inline()
                            ->required(),
                        Textarea::make('reported')
                            ->label(__('field.fields.reported'))
                            ->placeholder(__('field.helpers.reported'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('internal_notes')
                            ->label(__('field.fields.internal_notes'))
                            ->helperText(__('field.helpers.internal_notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('field.orders.sections.when'))
                    ->description(__('field.helpers.schedule_later'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->hiddenOn('edit')
                    ->components(static::scheduleFields(required: false)),
            ]);
    }

    /**
     * Active customers by name, trade name, code, document or phone (digits typed in any format).
     *
     * @return array<int, string>
     */
    public static function searchCustomers(string $search): array
    {
        $search = trim($search);
        $digits = (string) preg_replace('/\D/', '', $search);

        return Customer::query()->active()
            ->where(function (Builder $query) use ($search, $digits): void {
                $query->whereLike('name', "%{$search}%")->orWhereLike('trade_name', "%{$search}%")->orWhere('external_code', $search);

                if (strlen($digits) >= 3) {
                    $query->orWhereLike('document', "%{$digits}%")->orWhereLike('phone', "%{$digits}%");
                }
            })
            ->orderBy('name')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Customer $customer): array => [$customer->getKey() => $customer->displayName()])
            ->all();
    }

    /**
     * Technician, day and time, and how long (also used by the "Schedule" action).
     *
     * @return array<int, mixed>
     */
    public static function scheduleFields(bool $required, ?WorkOrder $order = null): array
    {
        return [
            Select::make('technician_id')
                ->label(__('field.fields.technician'))
                ->options(fn (): array => $order !== null ? static::nearestOptions($order) : static::technicianChoices())
                ->helperText(fn (): ?string => static::technicianOptions() === [] ? __('field.helpers.no_technicians') : ($order !== null ? __('field.orders.nearest_help') : null))
                // An expired safety training (NR-10, NR-35...) is shown, but never blocks the schedule.
                ->live()
                // The same for the PPE the type of service needs (People → PPE and tools): a warning only.
                ->belowContent(fn (Get $get): array => collect([
                    static::technicianWarning($get('technician_id')),
                    app(FieldEpi::class)->warning($get('technician_id'), $order?->service_type_id ?? $get('service_type_id')),
                ])->filter()->map(fn (string $warning): Text => Text::make($warning)->color('danger')->icon(Heroicon::OutlinedExclamationTriangle))->values()->all())
                ->requiredWith('scheduled_at')
                ->required($required)
                ->validationMessages(['required' => __('field.validation.technician'), 'required_with' => __('field.validation.technician_with_time')]),
            DateTimePicker::make('scheduled_at')
                ->label(__('field.fields.scheduled_at'))
                ->seconds(false)
                ->minutesStep(5)
                ->requiredWith('technician_id')
                ->required($required)
                ->validationMessages(['required' => __('field.validation.time'), 'required_with' => __('field.validation.time_with_technician')]),
            Select::make('duration_minutes')
                ->label(__('field.fields.duration'))
                ->options(collect([15, 30, 45, 60, 90, 120, 150, 180, 240, 300, 360, 480])->mapWithKeys(fn (int $minutes): array => [$minutes => FieldFormat::duration($minutes)])->all()),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'site', 'serviceType', 'technician']))
            ->columns([
                TextColumn::make('number')
                    ->label(__('field.fields.number'))
                    ->formatStateUsing(fn (WorkOrder $record): string => $record->label())
                    ->description(fn (WorkOrder $record): string => $record->serviceType->name)
                    ->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('number', (int) preg_replace('/\D/', '', $search) ?: 0))
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label(__('field.fields.customer'))
                    ->formatStateUsing(fn (WorkOrder $record): string => $record->customer->displayName())
                    ->description(fn (WorkOrder $record): ?string => $record->site ? collect([$record->site->district, $record->site->city])->filter()->implode(' - ') : null)
                    ->searchable(['name', 'trade_name'])
                    ->wrap(),
                TextColumn::make('scheduled_at')
                    ->label(__('field.fields.scheduled_at'))
                    ->formatStateUsing(fn (WorkOrder $record): string => static::when($record))
                    ->description(fn (WorkOrder $record): ?string => $record->technician?->name)
                    ->placeholder(__('field.orders.not_scheduled'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('field.fields.status'))
                    ->badge()
                    // Flags of the day: a return, a delay told by the technician, passed on by a technician, the customer's stars.
                    ->description(fn (WorkOrder $record): ?string => collect([
                        $record->return_of_id ? '↩ '.__('field.orders.flag_return') : null,
                        $record->delay_minutes && ! $record->status->isClosed() ? '⏱ +'.$record->delay_minutes.' min' : null,
                        $record->handoff_reason && $record->status === OrderStatus::Open ? '⇄ '.__('field.orders.flag_handoff') : null,
                        $record->rating ? $record->rating.' ★' : null,
                    ])->filter()->implode(' · ') ?: null),
                TextColumn::make('priority')
                    ->label(__('field.fields.priority'))
                    ->badge()
                    ->visibleFrom('md'),
            ])
            // The situation and "today" are the tabs above the list (ListOrders::getTabs).
            ->filters([
                SelectFilter::make('technician_id')
                    ->label(__('field.fields.technician'))
                    ->options(fn (): array => static::technicianOptions()),
                SelectFilter::make('service_type_id')
                    ->label(__('field.fields.service_type'))
                    ->relationship('serviceType', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('field.orders.empty'))
            ->emptyStateDescription(__('field.orders.empty_help'));
    }

    /** "Thu 31/10 · 14:00–15:30" in the company's time zone. */
    public static function when(WorkOrder $order): string
    {
        if ($order->scheduled_at === null) {
            return __('field.orders.not_scheduled');
        }

        $start = $order->scheduled_at->setTimezone(static::timezone());

        return ucfirst($start->translatedFormat(__('field.orders.day_format'))).' · '.$start->format('H:i').'–'.$order->endsAt()->setTimezone(static::timezone())->format('H:i');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make(__('field.orders.sections.what'))
                    ->columnSpan(['lg' => 2])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        TextEntry::make('customer.name')
                            ->label(__('field.fields.customer'))
                            ->formatStateUsing(fn (WorkOrder $record): string => $record->customer->displayName())
                            ->url(fn (WorkOrder $record): string => CustomerResource::getUrl('view', ['record' => $record->customer_id]))
                            ->color('primary')
                            ->weight('bold'),
                        TextEntry::make('customer.phone')
                            ->label(__('field.fields.phone'))
                            ->formatStateUsing(fn (?string $state): ?string => Country::formatPhone($state))
                            ->url(fn (WorkOrder $record): ?string => $record->customer->phone ? 'https://wa.me/'.$record->customer->phone : null, shouldOpenInNewTab: true)
                            ->color('primary')
                            ->placeholder('—'),
                        TextEntry::make('site')
                            ->label(__('field.fields.site'))
                            ->state(fn (WorkOrder $record): string => $record->site?->address() ?: __('field.sites.none'))
                            ->helperText(fn (WorkOrder $record): ?string => $record->site?->reference)
                            ->url(fn (WorkOrder $record): ?string => $record->site?->hasPoint() ? Geo::mapUrl($record->site->latitude, $record->site->longitude) : null, shouldOpenInNewTab: true)
                            ->columnSpanFull(),
                        TextEntry::make('serviceType.name')->label(__('field.fields.service_type')),
                        TextEntry::make('price_cents')->label(__('field.fields.price'))->formatStateUsing(fn (?int $state): string => FieldFormat::money($state))->placeholder('—'),
                        TextEntry::make('reported')->label(__('field.fields.reported'))->placeholder('—')->columnSpanFull(),
                        TextEntry::make('internal_notes')->label(__('field.fields.internal_notes'))->placeholder('—')->columnSpanFull(),
                        TextEntry::make('checklist')
                            ->label(__('field.service_types.checklist'))
                            ->state(fn (WorkOrder $record): array => collect($record->checklist)->map(fn (array $item): string => $item['label'].($item['required'] ? ' *' : ''))->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder(__('field.service_types.items', ['count' => 0]))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('field.orders.sections.when'))
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('status')->label(__('field.fields.status'))->badge(),
                        TextEntry::make('priority')->label(__('field.fields.priority'))->badge(),
                        TextEntry::make('technician.name')->label(__('field.fields.technician'))->placeholder(__('field.orders.no_technician')),
                        TextEntry::make('scheduled_at')->label(__('field.fields.scheduled_at'))->state(fn (WorkOrder $record): string => static::when($record)),
                        TextEntry::make('cancel_reason')->label(__('field.fields.cancel_reason'))->visible(fn (WorkOrder $record): bool => $record->status === OrderStatus::Canceled),
                        TextEntry::make('delay_minutes')
                            ->label(__('field.orders.delay'))
                            ->state(fn (WorkOrder $record): string => __('field.orders.delay_value', ['minutes' => $record->delay_minutes]).($record->delay_reason ? ' · '.$record->delay_reason : ''))
                            ->color('warning')
                            ->visible(fn (WorkOrder $record): bool => $record->delay_minutes !== null && ! $record->status->isClosed()),
                        TextEntry::make('handoff_reason')
                            ->label(__('field.orders.handoff'))
                            ->color('warning')
                            ->visible(fn (WorkOrder $record): bool => $record->handoff_reason !== null && $record->status === OrderStatus::Open),
                        TextEntry::make('return_of_id')
                            ->label(__('field.orders.return_of'))
                            ->state(fn (WorkOrder $record): ?string => $record->returnOf ? __('field.orders.return_value', ['order' => $record->returnOf->label(), 'date' => $record->returnOf->finished_at?->setTimezone(static::timezone())->translatedFormat(__('app.date_format'))]) : null)
                            ->url(fn (WorkOrder $record): ?string => $record->return_of_id ? static::getUrl('view', ['record' => $record->return_of_id]) : null)
                            ->badge()
                            ->color('danger')
                            ->visible(fn (WorkOrder $record): bool => $record->return_of_id !== null),
                        TextEntry::make('rating')
                            ->label(__('field.orders.rating'))
                            ->state(fn (WorkOrder $record): string => str_repeat('★', (int) $record->rating).str_repeat('☆', 5 - (int) $record->rating))
                            ->helperText(fn (WorkOrder $record): ?string => $record->rating_comment)
                            ->color(fn (WorkOrder $record): string => $record->rating >= 4 ? 'success' : 'danger')
                            ->visible(fn (WorkOrder $record): bool => $record->rated_at !== null),
                    ]),
                Section::make(__('field.orders.sections.execution'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'sm' => 2])
                    ->visible(fn (WorkOrder $record): bool => $record->checked_in_at !== null)
                    ->components([
                        TextEntry::make('checked_in_at')
                            ->label(__('field.orders.arrival'))
                            ->state(fn (WorkOrder $record): string => $record->checked_in_at->setTimezone(static::timezone())->translatedFormat(__('app.datetime_format'))
                                .($record->check_in_distance !== null ? ' · '.__('field.orders.distance', ['meters' => $record->check_in_distance]) : '')
                                .($record->check_in_km !== null ? ' · '.number_format($record->check_in_km, 0, ',', '.').' km' : ''))
                            ->url(fn (WorkOrder $record): ?string => $record->check_in_latitude !== null ? Geo::mapUrl($record->check_in_latitude, $record->check_in_longitude) : null, shouldOpenInNewTab: true),
                        TextEntry::make('finished_at')
                            ->label(__('field.orders.finished'))
                            ->state(fn (WorkOrder $record): ?string => $record->finished_at?->setTimezone(static::timezone())->translatedFormat(__('app.datetime_format')))
                            ->placeholder(fn (WorkOrder $record): string => $record->pause_reason ? __('field.events.paused', ['reason' => $record->pause_reason]) : '—'),
                        // Campo + Frota: the estimated cost of going to the customer (managers only; nothing without the data).
                        TextEntry::make('travel_cost')
                            ->label(__('connections.order.travel'))
                            ->state(function (WorkOrder $record): ?string {
                                $estimate = app(FieldFleet::class)->estimate($record);

                                return $estimate ? '≈ '.FleetFormat::money($estimate['cost_cents']).' · '.FleetFormat::distance($estimate['km']) : null;
                            })
                            ->helperText(function (WorkOrder $record): ?string {
                                $estimate = app(FieldFleet::class)->estimate($record);

                                return $estimate ? __('connections.order.travel_help', ['cost' => FleetFormat::money($estimate['per_km_cents']), 'unit' => FleetSetting::current()->distance_unit]) : null;
                            })
                            ->visible(fn (WorkOrder $record): bool => $record->status === OrderStatus::Done && app(FieldAccess::class)->isManager() && app(FieldFleet::class)->estimate($record) !== null),
                        TextEntry::make('answers_list')
                            ->label(__('field.service_types.checklist'))
                            ->state(fn (WorkOrder $record): array => static::answerLines($record))
                            ->listWithLineBreaks()
                            ->columnSpanFull(),
                        TextEntry::make('materials_list')
                            ->label(__('field.execute.materials'))
                            ->state(fn (WorkOrder $record): array => $record->materials->map(fn ($material): string => FieldFormat::quantity($material->quantity, $material->unit).' · '.$material->description)->all())
                            ->listWithLineBreaks()
                            ->placeholder('—'),
                        TextEntry::make('work_done')->label(__('field.execute.work_done'))->placeholder('—'),
                        ImageEntry::make('photo_paths')
                            ->label(__('field.orders.photos'))
                            ->state(fn (WorkOrder $record): array => $record->photos->pluck('path')->all())
                            ->disk('local')
                            ->visibility('private')
                            ->imageHeight(110)
                            ->placeholder('—')
                            ->columnSpanFull(),
                        ImageEntry::make('signature_path')
                            ->label(fn (WorkOrder $record): string => $record->signed_by ? __('field.execute.signed_by', ['name' => $record->signed_by]) : __('field.execute.signature'))
                            ->disk('local')
                            ->visibility('private')
                            ->imageHeight(90)
                            ->placeholder('—'),
                    ]),
                Section::make(__('field.orders.sections.history'))
                    ->columnSpanFull()
                    ->components([
                        RepeatableEntry::make('events')
                            ->hiddenLabel()
                            ->contained(false)
                            ->components([
                                TextEntry::make('type')
                                    ->hiddenLabel()
                                    ->state(fn (OrderEvent $record): string => static::eventText($record))
                                    ->helperText(fn (OrderEvent $record): string => $record->created_at->setTimezone(static::timezone())->translatedFormat(__('app.datetime_format')).($record->user ? ' · '.$record->user->name : '')),
                            ]),
                    ]),
            ]);
    }

    /**
     * Each checklist question with the technician's answer ("Signal power: -19.5 dBm", "Photo of the box: 2 photos").
     *
     * @return array<int, string>
     */
    public static function answerLines(WorkOrder $order): array
    {
        $answers = $order->answers->pluck('value', 'item_key');
        $photos = $order->photos->countBy('item_key');

        return collect((array) $order->checklist)->map(function (array $item) use ($answers, $photos): string {
            $value = $answers->get($item['key']);
            $text = match ($item['type']) {
                'photo' => ($photos->get($item['key'], 0) > 0 ? __('field.execute.photos_taken', ['count' => $photos->get($item['key'])]) : '—'),
                'check' => in_array($value, ['1', 'true'], true) ? '✓ '.__('field.execute.done_item') : '—',
                'yes_no' => in_array($value, ['yes', 'no'], true) ? __('field.execute.'.$value) : '—',
                'number' => filled($value) ? $value.($item['unit'] ? ' '.$item['unit'] : '') : '—',
                default => filled($value) ? (string) $value : '—',
            };

            return $item['label'].': '.$text;
        })->all();
    }

    /** One history line in words, in the language of who reads it. */
    public static function eventText(OrderEvent $event): string
    {
        $data = (array) $event->data;
        $at = fn (?string $value): string => $value ? CarbonImmutable::parse($value)->setTimezone(static::timezone())->translatedFormat(__('app.datetime_format')) : '—';

        return match ($event->type) {
            'opened' => __('field.events.opened'),
            'scheduled' => __('field.events.scheduled', ['technician' => $data['technician'] ?? '—', 'at' => $at($data['at'] ?? null)]),
            'rescheduled' => __('field.events.rescheduled', ['technician' => $data['technician'] ?? '—', 'at' => $at($data['at'] ?? null), 'from' => $at($data['from_at'] ?? null)]),
            'unscheduled' => __('field.events.unscheduled'),
            'canceled' => __('field.events.canceled', ['reason' => $data['reason'] ?? '']),
            'note' => __('field.events.note', ['text' => $data['text'] ?? '']),
            'edited' => __('field.events.edited'),
            'checked_in' => __('field.events.checked_in').(isset($data['distance']) ? ' · '.__('field.orders.distance', ['meters' => $data['distance']]) : '').(isset($data['km']) ? ' · '.number_format((int) $data['km'], 0, ',', '.').' km' : '').(isset($data['km_note']) ? ' ('.$data['km_note'].')' : ''),
            'paused' => __('field.events.paused', ['reason' => $data['reason'] ?? '']),
            'finished' => __('field.events.finished'),
            'on_the_way' => __('field.events.on_the_way'),
            'delayed' => __('field.events.delayed', ['minutes' => $data['minutes'] ?? '—']).(filled($data['reason'] ?? null) ? ': '.$data['reason'] : ''),
            'handed_off' => __('field.events.handed_off', ['technician' => $data['technician'] ?? '—', 'reason' => $data['reason'] ?? '']),
            'rated' => __('field.events.rated', ['rating' => $data['rating'] ?? '—']).(filled($data['comment'] ?? null) ? ': '.$data['comment'] : ''),
            default => $event->type,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/nova'),
            'agenda' => Pages\Agenda::route('/agenda'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/editar'),
        ];
    }
}
