<?php

namespace App\Modules\Field\Filament\Customers;

use App\Filament\Support\MapPoint;
use App\Filament\Support\ModuleResource;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Services\AddressLocator;
use App\Modules\Field\Services\CustomerRules;
use App\Support\Country;
use App\Support\Geo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers (manager and service desk): who receives the services, with one or more service addresses and the
 * point of each one on the map. In Brazil the postal code (CEP) fills the address; without a point, the address
 * is searched on the map in the background and the technician confirms it on the first visit.
 * Also: import from a spreadsheet (model to download in the person's language).
 */
class CustomerResource extends ModuleResource
{
    protected static string $moduleKey = FieldModule::KEY;

    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'campo/clientes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /** Phones as people type them, e.g. "(31) 98888-1234" or "+55 (31) 98888-1234". */
    public const PHONE_PATTERN = '/^[+0-9()\s.\-\/]{6,30}$/';

    public static function getModelLabel(): string
    {
        return __('field.customers.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('field.customers.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('field.customers.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('field.customers.singular_title');
    }

    public static function form(Schema $schema): Schema
    {
        $brazil = Country::isBrazil();

        return $schema
            ->columns(1)
            ->components([
                Section::make(__('field.customers.sections.customer'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        ToggleButtons::make('kind')
                            ->label(__('field.fields.kind'))
                            ->options([Customer::COMPANY => __('field.kinds.company'), Customer::PERSON => __('field.kinds.person')])
                            ->icons([Customer::COMPANY => Heroicon::OutlinedBuildingOffice, Customer::PERSON => Heroicon::OutlinedUser])
                            ->default(Customer::COMPANY)
                            ->inline()
                            ->live()
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->label(fn (Get $get): string => $get('kind') === Customer::PERSON ? __('field.fields.full_name') : __('field.fields.company_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('trade_name')
                            ->label(__('field.fields.trade_name'))
                            ->visible(fn (Get $get): bool => $get('kind') !== Customer::PERSON)
                            ->maxLength(255),
                        Select::make('document_type')
                            ->label(__('field.fields.document_type'))
                            ->options(collect(['tax_id', 'id_card', 'passport', 'rut', 'rfc', 'nit', 'cuit', 'dni', 'ruc', 'ein', 'nif'])->mapWithKeys(fn (string $type): array => [$type => __('field.document_types.'.$type)])->all())
                            ->visible(! $brazil),
                        TextInput::make('document')
                            ->label($brazil ? __('field.fields.cpf_cnpj') : __('field.fields.document'))
                            ->helperText($brazil ? __('field.helpers.cpf_cnpj') : null)
                            ->maxLength(40)
                            ->afterStateHydrated(fn (TextInput $component, ?Customer $record) => $record ? $component->state(app(CustomerRules::class)->typedDocument($record)) : null)
                            ->rule(fn (?Customer $record) => app(CustomerRules::class)->documentRule($record)),
                        TextInput::make('external_code')
                            ->label(__('field.fields.external_code'))
                            ->helperText(__('field.helpers.external_code'))
                            ->maxLength(60)
                            ->rule(fn (?Customer $record) => app(CustomerRules::class)->externalCodeRule($record)),
                        TextInput::make('phone')
                            ->label(__('field.fields.phone'))
                            ->helperText(__('field.helpers.phone'))
                            ->tel()
                            ->telRegex(self::PHONE_PATTERN)
                            ->afterStateHydrated(fn (TextInput $component, ?string $state) => $component->state(Country::formatPhone($state)))
                            ->maxLength(30),
                        TextInput::make('phone_2')
                            ->label(__('field.fields.phone_2'))
                            ->tel()
                            ->telRegex(self::PHONE_PATTERN)
                            ->afterStateHydrated(fn (TextInput $component, ?string $state) => $component->state(Country::formatPhone($state)))
                            ->maxLength(30),
                        TextInput::make('email')
                            ->label(__('field.fields.email'))
                            ->email()
                            ->maxLength(255),
                        Textarea::make('notes')
                            ->label(__('field.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label(__('field.fields.active'))
                            ->helperText(__('field.helpers.active'))
                            ->default(true)
                            ->hiddenOn('create'),
                    ]),
                Section::make(__('field.customers.sections.sites'))
                    ->description(__('field.helpers.sites'))
                    ->components([
                        Repeater::make('sites')
                            ->hiddenLabel()
                            ->relationship('sites')
                            ->defaultItems(1)
                            ->addActionLabel(__('field.sites.add'))
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => collect([$state['label'] ?? null, collect([$state['street'] ?? null, $state['number'] ?? null])->filter()->implode(', ')])->filter()->implode(' · ') ?: null)
                            // An address left empty is not saved.
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): ?array => static::isEmptySite($data) ? null : static::siteData($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => static::siteData($data))
                            ->components(static::siteFields()),
                    ]),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    public static function siteFields(): array
    {
        $brazil = Country::isBrazil();

        return [
            Grid::make(['default' => 1, 'sm' => 6])->components([
                TextInput::make('label')
                    ->label(__('field.fields.site_label'))
                    ->placeholder(__('field.helpers.site_label'))
                    ->maxLength(80)
                    ->columnSpan(['sm' => 4]),
                Toggle::make('is_main')
                    ->label(__('field.fields.main_site'))
                    ->inline(false)
                    ->columnSpan(['sm' => 2]),
                TextInput::make('postal_code')
                    ->label($brazil ? __('field.fields.cep') : __('field.fields.postal_code'))
                    ->maxLength(20)
                    ->columnSpan(['sm' => 2])
                    ->suffixAction(
                        Action::make('searchPostalCode')
                            ->icon(Heroicon::OutlinedMagnifyingGlass)
                            ->tooltip(__('field.sites.search_cep'))
                            ->visible($brazil)
                            ->action(function (Get $get, Set $set): void {
                                $address = app(AddressLocator::class)->postalCode($get('postal_code'));

                                if ($address === null) {
                                    Notification::make()->warning()->title(__('field.sites.cep_not_found'))->send();

                                    return;
                                }

                                foreach ($address as $field => $value) {
                                    if (filled($value)) {
                                        $set($field, $value);
                                    }
                                }
                            }),
                    ),
                TextInput::make('street')
                    ->label(__('field.fields.street'))
                    ->maxLength(255)
                    ->columnSpan(['sm' => 3]),
                TextInput::make('number')
                    ->label(__('field.fields.number'))
                    ->maxLength(20)
                    ->columnSpan(['sm' => 1]),
                TextInput::make('complement')
                    ->label(__('field.fields.complement'))
                    ->maxLength(120)
                    ->columnSpan(['sm' => 2]),
                TextInput::make('district')
                    ->label(__('field.fields.district'))
                    ->maxLength(120)
                    ->columnSpan(['sm' => 2]),
                TextInput::make('city')
                    ->label(__('field.fields.city'))
                    ->maxLength(120)
                    ->columnSpan(['sm' => 2]),
                TextInput::make('state')
                    ->label(__('field.fields.state'))
                    ->maxLength(60)
                    ->columnSpan(['sm' => 2]),
                Textarea::make('reference')
                    ->label(__('field.fields.reference'))
                    ->placeholder(__('field.helpers.reference'))
                    ->rows(2)
                    ->columnSpanFull(),
                TextInput::make('contact_name')
                    ->label(__('field.fields.contact_name'))
                    ->maxLength(120)
                    ->columnSpan(['sm' => 3]),
                TextInput::make('contact_phone')
                    ->label(__('field.fields.contact_phone'))
                    ->tel()
                    ->telRegex(self::PHONE_PATTERN)
                    ->afterStateHydrated(fn (TextInput $component, ?string $state) => $component->state(Country::formatPhone($state)))
                    ->maxLength(30)
                    ->columnSpan(['sm' => 3]),
            ]),
            Section::make(__('field.sites.map'))
                ->description(__('field.sites.map_help'))
                ->compact()
                ->components([MapPoint::make()]),
            Hidden::make('country')->default(fn (): string => Country::current()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function siteData(array $data): array
    {
        $data['contact_phone'] = Country::phone($data['contact_phone'] ?? null);
        $data['country'] = filled($data['country'] ?? null) ? $data['country'] : Country::current();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function isEmptySite(array $data): bool
    {
        return collect(['label', 'postal_code', 'street', 'number', 'complement', 'district', 'city', 'state', 'reference', 'contact_name', 'contact_phone', 'latitude', 'longitude'])
            ->every(fn (string $field): bool => blank($data[$field] ?? null));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('mainSite')->withCount('sites'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('field.fields.name'))
                    ->formatStateUsing(fn (Customer $record): string => $record->displayName())
                    ->description(fn (Customer $record): ?string => collect([$record->external_code ? '#'.$record->external_code : null, $record->documentLabel()])->filter()->implode(' · ') ?: null)
                    ->weight('bold')
                    ->searchable(['name', 'trade_name', 'document', 'external_code', 'phone', 'email'])
                    ->sortable(),
                TextColumn::make('mainSite.city')
                    ->label(__('field.fields.address'))
                    ->state(fn (Customer $record): ?string => $record->mainSite?->address())
                    ->description(fn (Customer $record): ?string => $record->sites_count > 1 ? trans_choice('field.sites.more', $record->sites_count - 1) : null)
                    ->placeholder(__('field.sites.none'))
                    ->wrap(),
                TextColumn::make('phone')
                    ->label(__('field.fields.phone'))
                    ->formatStateUsing(fn (?string $state): ?string => Country::formatPhone($state))
                    ->url(fn (Customer $record): ?string => $record->phone ? 'https://wa.me/'.$record->phone : null, shouldOpenInNewTab: true)
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('map')
                    ->label(__('field.sites.map'))
                    ->state(fn (Customer $record): string => static::pointLabel($record->mainSite))
                    ->badge()
                    ->color(fn (Customer $record): string => static::pointColor($record->mainSite))
                    ->url(fn (Customer $record): ?string => $record->mainSite?->hasPoint() ? Geo::mapUrl($record->mainSite->latitude, $record->mainSite->longitude) : null, shouldOpenInNewTab: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('field.fields.active'))
                    ->default(true),
                SelectFilter::make('kind')
                    ->label(__('field.fields.kind'))
                    ->options([Customer::COMPANY => __('field.kinds.company'), Customer::PERSON => __('field.kinds.person')]),
                SelectFilter::make('city')
                    ->label(__('field.fields.city'))
                    ->options(fn (): array => Site::query()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city', 'city')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereHas('sites', fn (Builder $sites) => $sites->where('city', $data['value'])) : $query)
                    ->searchable(),
                SelectFilter::make('point')
                    ->label(__('field.sites.map'))
                    ->options(['without' => __('field.sites.points.without'), 'not_found' => __('field.sites.points.not_found')])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'without' => $query->whereHas('sites', fn (Builder $sites) => $sites->whereNull('latitude')),
                        'not_found' => $query->whereHas('sites', fn (Builder $sites) => $sites->where('locate_status', Site::NOT_FOUND)),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('field.customers.empty'))
            ->emptyStateDescription(__('field.customers.empty_help'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('field.customers.sections.customer'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components([
                        TextEntry::make('kind')->label(__('field.fields.kind'))->formatStateUsing(fn (string $state): string => __('field.kinds.'.$state)),
                        TextEntry::make('document')->label(__('field.fields.document'))->state(fn (Customer $record): ?string => $record->documentLabel())->placeholder('—'),
                        TextEntry::make('external_code')->label(__('field.fields.external_code'))->placeholder('—'),
                        TextEntry::make('phone')->label(__('field.fields.phone'))
                            ->formatStateUsing(fn (string $state): ?string => Country::formatPhone($state))
                            ->url(fn (Customer $record): ?string => $record->phone ? 'https://wa.me/'.$record->phone : null, shouldOpenInNewTab: true)
                            ->color('primary')
                            ->placeholder('—'),
                        TextEntry::make('phone_2')->label(__('field.fields.phone_2'))->formatStateUsing(fn (string $state): ?string => Country::formatPhone($state))->placeholder('—'),
                        TextEntry::make('email')->label(__('field.fields.email'))->placeholder('—'),
                        TextEntry::make('notes')->label(__('field.fields.notes'))->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make(__('field.customers.sections.sites'))
                    ->components([
                        RepeatableEntry::make('sites')
                            ->hiddenLabel()
                            ->columns(['default' => 1, 'sm' => 2])
                            ->components([
                                TextEntry::make('label')
                                    ->hiddenLabel()
                                    ->state(fn (Site $record): string => ($record->label ?: __('field.sites.default_label')).($record->is_main ? ' · '.__('field.fields.main_site') : ''))
                                    ->weight('bold')
                                    ->columnSpanFull(),
                                TextEntry::make('address')->label(__('field.fields.address'))->state(fn (Site $record): string => $record->address() ?: '—'),
                                TextEntry::make('postal_code')->label(Country::isBrazil() ? __('field.fields.cep') : __('field.fields.postal_code'))->placeholder('—'),
                                TextEntry::make('reference')->label(__('field.fields.reference'))->placeholder('—'),
                                TextEntry::make('contact_name')->label(__('field.fields.contact_name'))
                                    ->state(fn (Site $record): ?string => collect([$record->contact_name, Country::formatPhone($record->contact_phone)])->filter()->implode(' · ') ?: null)
                                    ->placeholder('—'),
                                TextEntry::make('point')
                                    ->label(__('field.sites.map'))
                                    ->state(fn (Site $record): string => static::pointLabel($record))
                                    ->badge()
                                    ->color(fn (Site $record): string => static::pointColor($record))
                                    ->url(fn (Site $record): ?string => $record->hasPoint() ? Geo::mapUrl($record->latitude, $record->longitude) : null, shouldOpenInNewTab: true)
                                    ->helperText(fn (Site $record): ?string => $record->locate_status === Site::NOT_FOUND ? __('field.sites.points.not_found_help') : null),
                            ]),
                    ]),
                Section::make(__('field.customers.sections.history'))
                    ->components([
                        TextEntry::make('history')
                            ->hiddenLabel()
                            ->state(__('field.customers.history_soon'))
                            ->color('gray'),
                    ]),
            ]);
    }

    /** Where the point on the map came from, in words. */
    public static function pointLabel(?Site $site): string
    {
        return match (true) {
            $site === null => __('field.sites.points.without'),
            $site->hasPoint() => __('field.sites.points.'.($site->location_source ?? Site::TYPED)),
            $site->locate_status === Site::PENDING => __('field.sites.points.pending'),
            $site->locate_status === Site::NOT_FOUND => __('field.sites.points.not_found'),
            default => __('field.sites.points.without'),
        };
    }

    public static function pointColor(?Site $site): string
    {
        return match (true) {
            $site?->hasPoint() && $site->location_source === Site::SEARCH => 'warning',
            (bool) $site?->hasPoint() => 'success',
            $site?->locate_status === Site::NOT_FOUND => 'danger',
            default => 'gray',
        };
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'trade_name', 'document', 'external_code', 'phone'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/novo'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/editar'),
        ];
    }
}
