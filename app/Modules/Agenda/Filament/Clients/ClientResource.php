<?php

namespace App\Modules\Agenda\Filament\Clients;

use App\Filament\Support\ModuleResource;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Agenda\Enums\Species;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\Item;
use App\Modules\Agenda\Services\AgendaFormat;
use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Services\CustomerRules;
use App\Support\Country;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Clients (in a pet shop: the tutors) and their pets. The client is the company's customer of the Campo too (one
 * register, CustomerRules for the phone and the document); the pets (species, breed, size, coat, birth date, notes
 * and allergies, photo) are of the Agenda. The client's page shows the history, no-shows and packages.
 */
class ClientResource extends ModuleResource
{
    protected static string $moduleKey = AgendaModule::KEY;

    protected static ?string $model = Client::class;

    protected static ?string $langGroup = 'agenda.clients';

    protected static ?string $slug = 'agenda/clientes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('agenda.clients.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('agenda.clients.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('agenda.clients.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('agenda.clients.singular_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('agenda.clients.sections.client'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components(static::clientFields()),
                Section::make(__('agenda.clients.sections.items'))
                    ->description(__('agenda.clients.sections.items_help'))
                    ->components([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->relationship('items')
                            ->defaultItems(1)
                            ->addActionLabel(__('agenda.items.add'))
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            // A pet with appointments is turned off ("active"), never deleted.
                            ->deleteAction(fn (Action $action) => $action->visible(fn (array $arguments, Repeater $component): bool => ! static::itemHasHistory($component->getRawItemState($arguments['item'])['id'] ?? null)))
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): ?array => blank($data['name'] ?? null) ? null : $data)
                            ->components(static::itemFields()),
                    ]),
                Section::make(__('agenda.clients.sections.sites'))
                    ->description(__('agenda.clients.sections.sites_help'))
                    ->collapsible()
                    ->collapsed()
                    ->components([
                        Repeater::make('sites')
                            ->hiddenLabel()
                            ->relationship('sites')
                            ->defaultItems(0)
                            ->addActionLabel(__('field.sites.add'))
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => collect([$state['label'] ?? null, collect([$state['street'] ?? null, $state['number'] ?? null])->filter()->implode(', ')])->filter()->implode(' · ') ?: null)
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): ?array => CustomerResource::isEmptySite($data) ? null : CustomerResource::siteData($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => CustomerResource::siteData($data))
                            ->components(CustomerResource::siteFields()),
                    ]),
            ]);
    }

    /**
     * Name, phones, e-mail, document (optional) and notes: the same rules as the Campo customers.
     *
     * @return array<int, mixed>
     */
    public static function clientFields(): array
    {
        $brazil = Country::isBrazil();

        return [
            TextInput::make('name')
                ->label(__('agenda.fields.client_name'))
                ->required()
                ->maxLength(255),
            TextInput::make('phone')
                ->label(__('agenda.fields.phone'))
                ->helperText(__('agenda.helpers.phone'))
                ->tel()
                ->telRegex(CustomerResource::PHONE_PATTERN)
                ->afterStateHydrated(fn (TextInput $component, ?string $state) => $component->state(Country::formatPhone($state)))
                ->required()
                ->maxLength(30),
            TextInput::make('phone_2')
                ->label(__('field.fields.phone_2'))
                ->tel()
                ->telRegex(CustomerResource::PHONE_PATTERN)
                ->afterStateHydrated(fn (TextInput $component, ?string $state) => $component->state(Country::formatPhone($state)))
                ->maxLength(30),
            TextInput::make('email')
                ->label(__('field.fields.email'))
                ->email()
                ->maxLength(255),
            TextInput::make('document')
                ->label($brazil ? __('agenda.fields.cpf') : __('field.fields.document'))
                ->helperText(__('agenda.helpers.document'))
                ->maxLength(40)
                ->afterStateHydrated(fn (TextInput $component, ?Client $record) => $record ? $component->state(app(CustomerRules::class)->typedDocument($record)) : null)
                ->rule(fn (?Client $record) => app(CustomerRules::class)->documentRule($record)),
            Textarea::make('notes')
                ->label(__('field.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label(__('agenda.fields.active'))
                ->default(true)
                ->hiddenOn('create'),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function itemFields(): array
    {
        return [
            Grid::make(['default' => 1, 'sm' => 3])->components([
                TextInput::make('name')
                    ->label(__('agenda.fields.item_name'))
                    ->required()
                    ->maxLength(120),
                Select::make('species')
                    ->label(__('agenda.fields.species'))
                    ->options(collect(Species::cases())->mapWithKeys(fn (Species $species): array => [$species->value => $species->getLabel()])->all())
                    ->default(Species::Dog->value),
                TextInput::make('breed')
                    ->label(__('agenda.fields.breed'))
                    ->maxLength(120),
                Select::make('size')
                    ->label(__('agenda.fields.size'))
                    ->helperText(__('agenda.helpers.size'))
                    ->options(ItemSize::options()),
                Select::make('coat')
                    ->label(__('agenda.fields.coat'))
                    ->options(collect(Item::COATS)->mapWithKeys(fn (string $coat): array => [$coat => __('agenda.coats.'.$coat)])->all()),
                DatePicker::make('birth_date')
                    ->label(__('agenda.fields.birth_date'))
                    ->maxDate(now()),
                Textarea::make('notes')
                    ->label(__('agenda.fields.item_notes'))
                    ->placeholder(__('agenda.helpers.item_notes'))
                    ->rows(2)
                    ->columnSpan(['sm' => 2]),
                FileUpload::make('photo_path')
                    ->label(__('agenda.fields.photo'))
                    ->image()
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('800')
                    ->imageResizeTargetHeight('800')
                    ->disk('local')
                    ->visibility('private')
                    ->directory(fn (): string => 'agenda/'.app(TenantContext::class)->get()->getKey().'/pets')
                    ->maxSize(5120),
                Toggle::make('is_active')
                    ->label(__('agenda.fields.active'))
                    ->default(true),
            ]),
        ];
    }

    protected static function itemHasHistory(mixed $id): bool
    {
        return filled($id) && Appointment::query()->where('item_id', $id)->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('items');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('agenda.fields.client_name'))
                    ->weight('bold')
                    ->description(fn (Client $record): ?string => Country::formatPhone($record->phone))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%')
                        ->orWhereHas('items', fn (Builder $query) => $query->where('name', 'ilike', "%{$search}%")))),
                TextColumn::make('items_list')
                    ->label(__('agenda.items.plural_title'))
                    ->state(fn (Client $record): string => $record->items->where('is_active', true)->map(fn (Item $item): string => $item->label())->implode(', '))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('no_shows')
                    ->label(__('agenda.fields.no_shows'))
                    ->state(fn (Client $record): int => $record->noShows())
                    ->badge()
                    ->color(fn (Client $record, int $state): string => $record->isFlagged($state) ? 'danger' : 'gray')
                    ->visibleFrom('md'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('agenda.fields.active'))
                    ->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('agenda.clients.empty'))
            ->emptyStateDescription(__('agenda.clients.empty_help'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('agenda.clients.sections.client'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components([
                        TextEntry::make('phone')->label(__('agenda.fields.phone'))->formatStateUsing(fn (?string $state): ?string => Country::formatPhone($state))
                            ->url(fn (Client $record): ?string => $record->phone ? 'https://wa.me/'.$record->phone : null, shouldOpenInNewTab: true)->placeholder('—'),
                        TextEntry::make('email')->label(__('field.fields.email'))->placeholder('—'),
                        TextEntry::make('no_shows')->label(__('agenda.fields.no_shows'))
                            ->state(fn (Client $record): string => $record->isFlagged() ? __('agenda.clients.flagged', ['count' => $record->noShows()]) : (string) $record->noShows())
                            ->color(fn (Client $record): ?string => $record->isFlagged() ? 'danger' : null),
                        TextEntry::make('notes')->label(__('field.fields.notes'))->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make(__('agenda.items.plural_title'))
                    ->components([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(['default' => 1, 'sm' => 4])
                            ->schema([
                                ImageEntry::make('photo_url')->hiddenLabel()->state(fn (Item $record): ?string => static::photoDataUrl($record))->circular()->imageHeight(56),
                                TextEntry::make('name')->hiddenLabel()->weight('bold')->state(fn (Item $record): string => $record->label().($record->is_active ? '' : ' · '.__('agenda.fields.inactive'))),
                                TextEntry::make('species')->hiddenLabel()->state(fn (Item $record): string => collect([$record->species?->getLabel(), $record->coat ? __('agenda.coats.'.$record->coat) : null, $record->birth_date ? __('agenda.items.age', ['years' => (int) $record->birth_date->diffInYears(now())]) : null])->filter()->implode(' · ')),
                                TextEntry::make('notes')->hiddenLabel()->color('danger')->placeholder(''),
                            ]),
                    ]),
                Section::make(__('agenda.clients.sections.packages'))
                    ->visible(fn (Client $record): bool => $record->packages()->exists())
                    ->components([
                        TextEntry::make('packages_list')->hiddenLabel()->listWithLineBreaks()
                            ->state(fn (Client $record): array => $record->packages()->with('item')->get()->map(fn ($package): string => $package->label().($package->item ? ' · '.$package->item->name : ''))->all()),
                    ]),
                Section::make(__('agenda.clients.sections.history'))
                    ->components([
                        TextEntry::make('history')->hiddenLabel()->listWithLineBreaks()->placeholder(__('agenda.clients.no_history'))
                            ->state(fn (Client $record): array => $record->appointments()->with(['lines', 'item', 'professional.person'])->limit(30)->get()
                                ->map(fn (Appointment $appointment): string => AgendaFormat::when($appointment->starts_at).' · '.collect([$appointment->item?->name, $appointment->servicesLabel(), $appointment->professional?->name()])->filter()->implode(' · ').' · '.$appointment->status->getLabel())
                                ->all()),
                    ]),
            ]);
    }

    /** The pet's photo as a data URL (private file of the company). */
    public static function photoDataUrl(Item $item): ?string
    {
        $disk = Storage::disk('local');

        if (blank($item->photo_path) || ! $disk->exists($item->photo_path)) {
            return null;
        }

        return 'data:'.($disk->mimeType($item->photo_path) ?: 'image/jpeg').';base64,'.base64_encode($disk->get($item->photo_path));
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
