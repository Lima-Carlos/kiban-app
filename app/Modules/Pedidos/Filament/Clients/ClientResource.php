<?php

namespace App\Modules\Pedidos\Filament\Clients;

use App\Filament\Support\ModuleResource;
use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Pedidos\Enums\PaymentMethod;
use App\Modules\Pedidos\Enums\Rhythm;
use App\Modules\Pedidos\Models\Client;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\PedidosModule;
use App\Modules\Pedidos\Services\ClientService;
use App\Modules\Pedidos\Services\CustomerRhythm;
use App\Modules\Pedidos\Services\PedidosFormat;
use App\Support\Country;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Customers of the Pedidos: the company's customer register (shared with the Campo and the Agenda), with the
 * phone (one customer per phone), the delivery addresses (point on the map searched in the background), the usual
 * payment, the WhatsApp consent and, on the list, the rhythm (green, yellow, red).
 */
class ClientResource extends ModuleResource
{
    protected static string $moduleKey = PedidosModule::KEY;

    protected static ?string $model = Client::class;

    protected static ?string $langGroup = 'pedidos.clients';

    protected static ?string $slug = 'pedidos/clientes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    /** @var Collection<int, array<string, mixed>>|null */
    protected static ?Collection $rhythms = null;

    public static function getModelLabel(): string
    {
        return __('pedidos.clients.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pedidos.clients.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('pedidos.clients.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('pedidos.clients.singular_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pedidos.clients.sections.client'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        TextInput::make('name')->label(__('pedidos.fields.client_name'))->required()->maxLength(255),
                        TextInput::make('phone')->label(__('pedidos.fields.phone'))->helperText(__('pedidos.helpers.phone'))
                            ->tel()->telRegex(CustomerResource::PHONE_PATTERN)->required()->maxLength(30)
                            ->afterStateHydrated(fn (TextInput $component, ?string $state) => $component->state(Country::formatPhone($state)))
                            ->rule(fn (?Client $record) => app(ClientService::class)->phoneRule($record)),
                        Textarea::make('notes')->label(__('pedidos.fields.client_notes'))->placeholder(__('pedidos.helpers.client_notes'))->rows(2)->columnSpanFull(),
                        Toggle::make('is_active')->label(__('pedidos.fields.active'))->default(true)->hiddenOn('create'),
                    ]),
                Section::make(__('pedidos.clients.sections.orders'))
                    ->relationship('details')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        Select::make('payment_preference')->label(__('pedidos.fields.payment_preference'))->options(PaymentMethod::options()),
                        Toggle::make('whatsapp_consent')->label(__('pedidos.fields.whatsapp_consent'))->helperText(__('pedidos.helpers.whatsapp_consent'))->inline(false),
                    ]),
                Section::make(__('pedidos.clients.sections.sites'))
                    ->description(__('pedidos.clients.sections.sites_help'))
                    ->components([
                        Repeater::make('sites')
                            ->hiddenLabel()
                            ->relationship('sites')
                            ->defaultItems(1)
                            ->addActionLabel(__('field.sites.add'))
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => collect([$state['label'] ?? null, collect([$state['street'] ?? null, $state['number'] ?? null])->filter()->implode(', ')])->filter()->implode(' · ') ?: null)
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): ?array => CustomerResource::isEmptySite($data) ? null : CustomerResource::siteData($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => CustomerResource::siteData($data))
                            ->components(CustomerResource::siteFields()),
                    ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['mainSite', 'details']);
    }

    /**
     * The rhythm of every customer, read once per request.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function rhythms(): Collection
    {
        return static::$rhythms ??= app(CustomerRhythm::class)->all();
    }

    public static function rhythmOf(Client $client): Rhythm
    {
        return static::rhythms()->get($client->getKey())['rhythm'] ?? Rhythm::New;
    }

    public static function table(Table $table): Table
    {
        static::$rhythms = null;

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('pedidos.fields.client_name'))->weight('bold')
                    ->description(fn (Client $record): ?string => Country::formatPhone($record->phone))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                        ->where('name', 'ilike', "%{$search}%")
                        ->when(preg_replace('/\D/', '', $search) !== '', fn (Builder $query) => $query->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%')))),
                TextColumn::make('address')->label(__('pedidos.fields.address'))
                    ->state(fn (Client $record): ?string => $record->mainSite?->address())->placeholder('—')->wrap()->visibleFrom('md'),
                TextColumn::make('rhythm')->label(__('pedidos.fields.rhythm'))->badge()
                    ->state(fn (Client $record): Rhythm => static::rhythmOf($record))
                    ->description(fn (Client $record): ?string => PedidosFormat::rhythmLine(static::rhythms()->get($record->getKey()))),
            ])
            ->filters([
                SelectFilter::make('rhythm')->label(__('pedidos.fields.rhythm'))
                    ->options(collect(Rhythm::cases())->mapWithKeys(fn (Rhythm $rhythm): array => [$rhythm->value => $rhythm->getLabel()])->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $rhythm = Rhythm::from($data['value']);
                        $ids = static::rhythms()->filter(fn (array $row): bool => $row['rhythm'] === $rhythm)->keys()->all();

                        return $rhythm === Rhythm::New
                            ? $query->whereNotIn('id', static::rhythms()->reject(fn (array $row): bool => $row['rhythm'] === Rhythm::New)->keys()->all())
                            : $query->whereKey($ids);
                    }),
                TernaryFilter::make('is_active')->label(__('pedidos.fields.active'))->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('pedidos.clients.empty'))
            ->emptyStateDescription(__('pedidos.clients.empty_help'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pedidos.clients.sections.client'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components([
                        TextEntry::make('phone')->label(__('pedidos.fields.phone'))->formatStateUsing(fn (?string $state): ?string => Country::formatPhone($state))->placeholder('—'),
                        TextEntry::make('address')->label(__('pedidos.fields.address'))->state(fn (Client $record): ?string => $record->mainSite?->address())->placeholder('—'),
                        TextEntry::make('rhythm')->label(__('pedidos.fields.rhythm'))->badge()
                            ->state(fn (Client $record): Rhythm => static::rhythmOf($record))
                            ->helperText(fn (Client $record): ?string => PedidosFormat::rhythmLine(static::rhythms()->get($record->getKey()))),
                        TextEntry::make('payment')->label(__('pedidos.fields.payment_preference'))->state(fn (Client $record): ?string => $record->details?->payment_preference?->getLabel())->placeholder('—'),
                        TextEntry::make('consent')->label(__('pedidos.fields.whatsapp_consent'))
                            ->state(fn (Client $record): string => $record->hasWhatsAppConsent() ? __('pedidos.clients.consent_yes', ['date' => PedidosFormat::date($record->details->whatsapp_consent_at)]) : __('pedidos.clients.consent_no')),
                        TextEntry::make('notes')->label(__('pedidos.fields.client_notes'))->placeholder('—'),
                    ]),
                Section::make(__('pedidos.clients.sections.history'))
                    ->components([
                        TextEntry::make('history')->hiddenLabel()->listWithLineBreaks()->placeholder(__('pedidos.clients.no_history'))
                            ->state(fn (Client $record): array => Order::query()->with(['lines', 'preparer', 'deliverer'])->where('customer_id', $record->getKey())
                                ->latest('created_at')->limit(30)->get()
                                ->map(fn (Order $order): string => PedidosFormat::orderLine($order).' · '.Money::format($order->total_cents))
                                ->all()),
                    ]),
            ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/novo'),
            'view' => Pages\ViewClient::route('/{record}'),
            'edit' => Pages\EditClient::route('/{record}/editar'),
        ];
    }
}
