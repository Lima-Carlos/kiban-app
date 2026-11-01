<?php

namespace App\Modules\Agenda\Filament\Appointments;

use App\Filament\Support\ModuleResource;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\AgendaRole;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Agenda\Enums\Species;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\Item;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Models\Service;
use App\Modules\Agenda\Services\AgendaAccess;
use App\Modules\Agenda\Services\AgendaFormat;
use App\Modules\Agenda\Services\PackageService;
use App\Modules\Agenda\Services\SlotFinder;
use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Services\CustomerRules;
use App\Support\Country;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Appointments (the desk): book (client and pet, services with the price of the pet's size, professional, a free time
 * or a squeezed-in one, package, pick-up), the list with filters and the appointment's page with the way
 * (confirmed, arrived, in service, pet ready, delivered), no-show, cancel, move and the WhatsApp buttons.
 * A professional only sees their own appointments.
 */
class AppointmentResource extends ModuleResource
{
    protected static string $moduleKey = AgendaModule::KEY;

    protected static ?string $model = Appointment::class;

    protected static ?string $langGroup = 'agenda.appointments';

    protected static ?string $slug = 'agenda/agendamentos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 15;

    public static function getModelLabel(): string
    {
        return __('agenda.appointments.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('agenda.appointments.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('agenda.appointments.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('agenda.appointments.singular_title');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['client', 'item', 'professional.person', 'lines']);
        $access = app(AgendaAccess::class);

        // A professional only sees their own appointments.
        if ($access->role() === AgendaRole::Professional) {
            $query->where('professional_id', $access->professionalFor()?->getKey() ?? 0);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('agenda.appointments.sections.who'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        Select::make('customer_id')
                            ->label(__('agenda.fields.client'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => static::searchClients($search))
                            ->getOptionLabelUsing(fn ($value): ?string => static::clientLabel(Client::query()->find($value)))
                            ->default(fn (): ?int => request()->integer('cliente') ?: null)
                            ->live()
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('item_id', Item::query()->active()->where('customer_id', $state)->orderBy('name')->value('id')))
                            ->createOptionForm([
                                TextInput::make('name')->label(__('agenda.fields.client_name'))->required()->maxLength(255),
                                TextInput::make('phone')->label(__('agenda.fields.phone'))->tel()->telRegex(CustomerResource::PHONE_PATTERN)->required()->maxLength(30),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                $client = new Client;
                                $client->forceFill(app(CustomerRules::class)->prepare($data + ['kind' => Client::PERSON]))->save();

                                return $client->getKey();
                            })
                            ->required(),
                        Select::make('item_id')
                            ->label(__('agenda.fields.item'))
                            ->options(fn (Get $get): array => Item::query()->active()->where('customer_id', $get('customer_id'))->orderBy('name')->get()->mapWithKeys(fn (Item $item): array => [$item->getKey() => $item->label()])->all())
                            ->default(fn (): ?int => request()->integer('cliente') ? Item::query()->active()->where('customer_id', request()->integer('cliente'))->orderBy('name')->value('id') : null)
                            ->live()
                            ->createOptionForm([
                                TextInput::make('name')->label(__('agenda.fields.item_name'))->required()->maxLength(120),
                                Select::make('species')->label(__('agenda.fields.species'))->options(collect(Species::cases())->mapWithKeys(fn (Species $species): array => [$species->value => $species->getLabel()])->all())->default(Species::Dog->value),
                                TextInput::make('breed')->label(__('agenda.fields.breed'))->maxLength(120),
                                Select::make('size')->label(__('agenda.fields.size'))->options(ItemSize::options()),
                            ])
                            ->createOptionUsing(fn (array $data, Get $get): int => Item::create($data + ['customer_id' => $get('customer_id')])->getKey())
                            ->disabled(fn (Get $get): bool => blank($get('customer_id'))),
                        Select::make('size')
                            ->label(__('agenda.fields.size'))
                            ->helperText(__('agenda.helpers.size_when_no_item'))
                            ->options(ItemSize::options())
                            ->live()
                            ->visible(fn (Get $get): bool => static::item($get) === null || static::item($get)->size === null),
                    ]),
                Section::make(__('agenda.appointments.sections.what'))
                    ->components([
                        CheckboxList::make('service_ids')
                            ->label(__('agenda.fields.services'))
                            ->options(fn (Get $get): array => Service::query()->active()->orderBy('is_addon')->orderBy('sort')->get()
                                ->mapWithKeys(fn (Service $service): array => [$service->getKey() => $service->name.' · '.AgendaFormat::money($service->priceFor(static::size($get))).($service->is_pickup ? '' : ' · '.AgendaFormat::duration($service->durationFor(static::size($get))))])
                                ->all())
                            ->columns(['default' => 1, 'sm' => 2])
                            ->live()
                            ->required(),
                    ]),
                Section::make(__('agenda.appointments.sections.when'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components([
                        Select::make('professional_id')
                            ->label(__('agenda.fields.professional'))
                            ->options(fn (Get $get): array => static::professionalOptions((array) $get('service_ids')))
                            ->default(fn (): ?int => request()->integer('profissional') ?: null)
                            ->live()
                            ->required(),
                        DatePicker::make('date')
                            ->label(__('agenda.fields.date'))
                            ->default(fn (): string => request()->query('data') ?: CarbonImmutable::now(AgendaSetting::timezone())->toDateString())
                            ->live()
                            ->required(),
                        Select::make('time')
                            ->label(__('agenda.fields.time'))
                            ->helperText(fn (Get $get): ?string => static::freeTimes($get) === [] ? __('agenda.appointments.no_free_time') : null)
                            ->options(fn (Get $get): array => static::freeTimes($get))
                            ->default(fn (): ?string => request()->query('hora'))
                            ->required(fn (Get $get): bool => ! $get('squeeze'))
                            ->visible(fn (Get $get): bool => ! $get('squeeze')),
                        Toggle::make('squeeze')
                            ->label(__('agenda.fields.squeeze'))
                            ->helperText(__('agenda.helpers.squeeze'))
                            ->live()
                            ->columnSpan(['sm' => 2]),
                        TimePicker::make('other_time')
                            ->label(__('agenda.fields.time'))
                            ->seconds(false)
                            ->format('H:i')
                            ->timezone(config('app.timezone'))
                            ->visible(fn (Get $get): bool => (bool) $get('squeeze'))
                            ->required(fn (Get $get): bool => (bool) $get('squeeze')),
                        Select::make('client_package_id')
                            ->label(__('agenda.fields.package'))
                            ->placeholder(__('agenda.appointments.no_package'))
                            ->options(fn (Get $get): array => static::packageOptions($get))
                            ->visible(fn (Get $get): bool => static::packageOptions($get) !== [])
                            ->columnSpanFull(),
                    ]),
                Section::make(__('agenda.appointments.sections.pickup'))
                    ->description(__('agenda.appointments.sections.pickup_help'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->collapsible()
                    ->collapsed(fn (Get $get): bool => ! static::wantsPickup($get))
                    ->components([
                        Toggle::make('pickup')->label(__('agenda.fields.pickup'))->live(),
                        Toggle::make('dropoff')->label(__('agenda.fields.dropoff'))->live(),
                        Select::make('pickup_site_id')
                            ->label(__('agenda.fields.address'))
                            ->options(fn (Get $get): array => Site::query()->where('customer_id', $get('customer_id'))->get()->mapWithKeys(fn (Site $site): array => [$site->getKey() => $site->address()])->all())
                            ->placeholder(__('agenda.appointments.new_address'))
                            ->visible(fn (Get $get): bool => static::wantsPickup($get))
                            ->live(),
                        TextInput::make('pickup_address')
                            ->label(__('agenda.fields.new_address'))
                            ->maxLength(250)
                            ->visible(fn (Get $get): bool => static::wantsPickup($get) && blank($get('pickup_site_id')))
                            ->required(fn (Get $get): bool => static::wantsPickup($get) && blank($get('pickup_site_id'))),
                        TimePicker::make('pickup_from')->label(__('agenda.fields.pickup_from'))->seconds(false)->format('H:i')->timezone(config('app.timezone'))->visible(fn (Get $get): bool => static::wantsPickup($get)),
                        TimePicker::make('pickup_until')->label(__('agenda.fields.pickup_until'))->seconds(false)->format('H:i')->timezone(config('app.timezone'))->visible(fn (Get $get): bool => static::wantsPickup($get)),
                    ]),
                Textarea::make('notes')
                    ->label(__('agenda.fields.notes'))
                    ->placeholder(__('agenda.helpers.notes'))
                    ->rows(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        $timezone = AgendaSetting::timezone();

        return $table
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')
                    ->label(__('agenda.fields.when'))
                    ->formatStateUsing(fn (Appointment $record): string => AgendaFormat::when($record->starts_at))
                    ->sortable(),
                TextColumn::make('item.name')
                    ->label(__('agenda.fields.item'))
                    ->state(fn (Appointment $record): string => $record->title())
                    ->description(fn (Appointment $record): ?string => $record->item ? $record->client?->name : null)
                    ->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                        ->whereHas('client', fn (Builder $query) => $query->where('name', 'ilike', "%{$search}%"))
                        ->orWhereHas('item', fn (Builder $query) => $query->where('name', 'ilike', "%{$search}%")))),
                TextColumn::make('services')
                    ->label(__('agenda.fields.services'))
                    ->state(fn (Appointment $record): string => $record->servicesLabel())
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('professional.person.name')
                    ->label(__('agenda.fields.professional'))
                    ->visibleFrom('md'),
                TextColumn::make('status')
                    ->label(__('agenda.fields.status'))
                    ->badge(),
                TextColumn::make('origin')
                    ->label(__('agenda.fields.origin'))
                    ->formatStateUsing(fn (string $state): string => __('agenda.origins.'.$state))
                    ->visibleFrom('lg'),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__('agenda.fields.from'))->default(CarbonImmutable::now($timezone)->toDateString()),
                        DatePicker::make('until')->label(__('agenda.fields.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $from) => $query->where('starts_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn (Builder $query, $until) => $query->where('starts_at', '<=', CarbonImmutable::parse($until, $timezone)->endOfDay()->utc()))),
                SelectFilter::make('status')
                    ->label(__('agenda.fields.status'))
                    ->options(collect(AppointmentStatus::cases())->mapWithKeys(fn (AppointmentStatus $status): array => [$status->value => $status->getLabel()])->all())
                    ->multiple(),
                SelectFilter::make('professional_id')
                    ->label(__('agenda.fields.professional'))
                    ->options(fn (): array => static::professionalOptions([])),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('agenda.appointments.empty'))
            ->emptyStateDescription(__('agenda.appointments.empty_help'));
    }

    /**
     * @return array<int|string, string>
     */
    public static function professionalOptions(array $serviceIds): array
    {
        $services = Service::query()->whereKey(array_filter($serviceIds))->get();

        return Professional::query()->active()->with(['person', 'services'])->orderBy('sort')->get()
            ->filter(fn (Professional $professional): bool => $services->every(fn (Service $service): bool => $professional->does($service)))
            ->mapWithKeys(fn (Professional $professional): array => [$professional->getKey() => $professional->name()])
            ->all();
    }

    /**
     * Free times ("09:30") of the chosen professional and day for the chosen services.
     *
     * @return array<string, string>
     */
    public static function freeTimes(Get $get): array
    {
        $professional = filled($get('professional_id')) ? Professional::query()->find($get('professional_id')) : null;

        if ($professional === null || blank($get('date'))) {
            return [];
        }

        $finder = app(SlotFinder::class);
        $minutes = max(AgendaSetting::current()->slot_minutes, $finder->minutes(Service::query()->whereKey(array_filter((array) $get('service_ids')))->get(), static::size($get)));
        $date = CarbonImmutable::parse((string) $get('date'), AgendaSetting::timezone());

        return collect($finder->slots($professional, $date, $minutes))
            ->mapWithKeys(fn (CarbonImmutable $time): array => [$time->format('H:i') => $time->format('H:i')])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function packageOptions(Get $get): array
    {
        if (blank($get('customer_id')) || blank($get('date'))) {
            return [];
        }

        $day = CarbonImmutable::parse((string) $get('date'), AgendaSetting::timezone());

        return Service::query()->whereKey(array_filter((array) $get('service_ids')))->where('is_addon', false)->get()
            ->flatMap(fn (Service $service) => app(PackageService::class)->usable((int) $get('customer_id'), $get('item_id') ? (int) $get('item_id') : null, $service, $day))
            ->mapWithKeys(fn ($package): array => [$package->getKey() => $package->label()])
            ->all();
    }

    public static function item(Get $get): ?Item
    {
        return filled($get('item_id')) ? Item::query()->find($get('item_id')) : null;
    }

    public static function size(Get $get): ?ItemSize
    {
        return static::item($get)?->size ?? ItemSize::tryFrom((string) $get('size'));
    }

    public static function wantsPickup(Get $get): bool
    {
        return (bool) $get('pickup') || (bool) $get('dropoff');
    }

    /**
     * Clients by name, phone or pet name.
     *
     * @return array<int, string>
     */
    public static function searchClients(string $search): array
    {
        $digits = preg_replace('/\D/', '', $search);

        return Client::query()->active()
            ->where(fn (Builder $query) => $query->where('name', 'ilike', "%{$search}%")
                ->when(strlen($digits) >= 4, fn (Builder $query) => $query->orWhere('phone', 'like', "%{$digits}%"))
                ->orWhereHas('items', fn (Builder $query) => $query->where('name', 'ilike', "%{$search}%")))
            ->with('items')
            ->orderBy('name')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Client $client): array => [$client->getKey() => static::clientLabel($client)])
            ->all();
    }

    public static function clientLabel(?Client $client): ?string
    {
        if ($client === null) {
            return null;
        }

        $pets = $client->items->where('is_active', true)->pluck('name')->implode(', ');

        return $client->name.($client->phone ? ' · '.Country::formatPhone($client->phone) : '').($pets !== '' ? ' · '.$pets : '');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppointments::route('/'),
            'create' => Pages\CreateAppointment::route('/novo'),
            'view' => Pages\ViewAppointment::route('/{record}'),
        ];
    }
}
