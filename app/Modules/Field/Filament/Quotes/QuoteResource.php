<?php

namespace App\Modules\Field\Filament\Quotes;

use App\Filament\Support\ModuleResource;
use App\Modules\Field\Enums\QuoteStatus;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Filament\FieldFields;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\QuoteItem;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Services\FieldFormat;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quotes (manager and service desk): services and materials of the catalog (or free lines), quantity and price,
 * discount, validity and the notes the customer reads. Sent by a link (WhatsApp); the customer approves or
 * declines on the phone, and the approved quote becomes a work order by itself.
 */
class QuoteResource extends ModuleResource
{
    protected static string $moduleKey = FieldModule::KEY;

    protected static ?string $model = Quote::class;

    protected static ?string $slug = 'campo/orcamentos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('field.quotes.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('field.quotes.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('field.quotes.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('field.quotes.singular_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('field.quotes.sections.customer'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components([
                        Select::make('customer_id')
                            ->label(__('field.fields.customer'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => OrderResource::searchCustomers($search))
                            ->getOptionLabelUsing(fn ($value): ?string => Customer::query()->find($value)?->displayName())
                            ->helperText(__('field.helpers.order_customer'))
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => $set('site_id', Customer::query()->find($state)?->mainSite?->getKey()))
                            ->required()
                            ->disabledOn('edit')
                            ->columnSpan(['sm' => 2]),
                        DatePicker::make('valid_until')
                            ->label(__('field.fields.valid_until'))
                            ->minDate(fn (): string => CarbonImmutable::now(OrderResource::timezone())->toDateString()),
                        Select::make('site_id')
                            ->label(__('field.fields.site'))
                            ->options(fn (Get $get): array => Site::query()->where('customer_id', $get('customer_id'))->orderByDesc('is_main')->get()
                                ->mapWithKeys(fn (Site $site): array => [$site->getKey() => collect([$site->label, $site->address()])->filter()->implode(' · ') ?: __('field.sites.default_label')])->all())
                            ->visible(fn (Get $get): bool => filled($get('customer_id')))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('field.quotes.sections.items'))
                    ->components([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->addActionLabel(__('field.quotes.add_item'))
                            ->reorderable()
                            ->defaultItems(1)
                            ->minItems(1)
                            ->live()
                            ->components([
                                Hidden::make('service_type_id'),
                                Hidden::make('material_id'),
                                Grid::make(['default' => 2, 'sm' => 12])->components([
                                    Select::make('catalog')
                                        ->label(__('field.fields.from_catalog'))
                                        ->placeholder(__('field.helpers.from_catalog'))
                                        ->options(fn (): array => static::catalogOptions())
                                        ->searchable()
                                        ->dehydrated(false)
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set, ?string $state) => static::fillFromCatalog($set, $state))
                                        ->columnSpan(['default' => 2, 'sm' => 12]),
                                    TextInput::make('description')
                                        ->label(__('field.fields.description'))
                                        ->required()
                                        ->maxLength(255)
                                        ->columnSpan(['default' => 2, 'sm' => 6]),
                                    TextInput::make('quantity')
                                        ->label(__('field.fields.quantity'))
                                        ->numeric()
                                        ->minValue(0.01)
                                        ->default(1)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->columnSpan(['default' => 1, 'sm' => 2]),
                                    Select::make('unit')
                                        ->label(__('field.fields.unit'))
                                        ->options(collect(Material::UNITS)->mapWithKeys(fn (string $unit): array => [$unit => __('field.units.'.$unit)])->all())
                                        ->default('un')
                                        ->required()
                                        ->columnSpan(['default' => 1, 'sm' => 2]),
                                    FieldFields::price('unit_price_cents')
                                        ->label(__('field.fields.unit_price'))
                                        ->required()
                                        ->live(onBlur: true)
                                        ->columnSpan(['default' => 2, 'sm' => 2]),
                                ]),
                            ]),
                        Text::make(fn (Get $get): string => __('field.quotes.subtotal_live', ['total' => FieldFormat::money(static::subtotal((array) $get('items')))])),
                    ]),
                Section::make(__('field.quotes.sections.terms'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        FieldFields::price('discount_cents')
                            ->label(__('field.fields.discount')),
                        Textarea::make('notes')
                            ->label(__('field.fields.quote_notes'))
                            ->placeholder(__('field.helpers.quote_notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('internal_notes')
                            ->label(__('field.fields.internal_notes'))
                            ->helperText(__('field.helpers.internal_notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Services and materials of the catalog, grouped ("s:3", "m:7").
     *
     * @return array<string, array<string, string>>
     */
    public static function catalogOptions(): array
    {
        $price = fn (?int $cents): string => $cents === null ? '' : ' · '.FieldFormat::money($cents);

        return [
            __('field.service_types.plural_title') => ServiceType::query()->active()->orderBy('sort')->get()
                ->mapWithKeys(fn (ServiceType $type): array => ['s:'.$type->getKey() => $type->name.$price($type->price_cents)])->all(),
            __('field.materials.plural_title') => Material::query()->active()->orderBy('name')->get()
                ->mapWithKeys(fn (Material $material): array => ['m:'.$material->getKey() => $material->name.$price($material->price_cents)])->all(),
        ];
    }

    /** Choosing from the catalog fills the line (the person may still change it). */
    public static function fillFromCatalog(Set $set, ?string $choice): void
    {
        [$kind, $id] = array_pad(explode(':', (string) $choice, 2), 2, null);
        $record = match ($kind) {
            's' => ServiceType::query()->find($id),
            'm' => Material::query()->find($id),
            default => null,
        };

        if ($record === null) {
            return;
        }

        $set('service_type_id', $record instanceof ServiceType ? $record->getKey() : null);
        $set('material_id', $record instanceof Material ? $record->getKey() : null);
        $set('description', $record->name);
        $set('unit', $record instanceof Material ? $record->unit : 'un');
        $set('unit_price_cents', $record->price_cents === null ? null : number_format($record->price_cents / 100, 2, '.', ''));
    }

    /**
     * Subtotal of the lines as typed on the form (prices typed in money, not cents).
     *
     * @param  array<int|string, mixed>  $items
     */
    public static function subtotal(array $items): int
    {
        return (int) collect($items)->sum(fn ($item): int => is_array($item)
            ? (int) round((float) ($item['quantity'] ?? 0) * (float) ($item['unit_price_cents'] ?? 0) * 100)
            : 0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'order']))
            ->columns([
                TextColumn::make('number')
                    ->label(__('field.fields.number'))
                    ->formatStateUsing(fn (Quote $record): string => $record->label())
                    ->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('number', (int) preg_replace('/\D/', '', $search) ?: 0))
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label(__('field.fields.customer'))
                    ->formatStateUsing(fn (Quote $record): string => $record->customer->displayName())
                    ->searchable(['name', 'trade_name'])
                    ->wrap(),
                TextColumn::make('total_cents')
                    ->label(__('field.fields.total'))
                    ->formatStateUsing(fn (int $state): string => FieldFormat::money($state))
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label(__('field.fields.status'))
                    ->formatStateUsing(fn (Quote $record): string => $record->isExpired() ? __('field.quotes.expired') : $record->status->getLabel())
                    ->color(fn (Quote $record): string => $record->isExpired() ? 'warning' : $record->status->getColor())
                    ->description(fn (Quote $record): ?string => $record->order?->label())
                    ->badge(),
                TextColumn::make('valid_until')
                    ->label(__('field.fields.valid_until'))
                    ->date(__('app.date_format'))
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('field.fields.status'))
                    ->options(QuoteStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Quote $record): bool => $record->isEditable()),
            ])
            ->emptyStateHeading(__('field.quotes.empty'))
            ->emptyStateDescription(__('field.quotes.empty_help'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make(__('field.quotes.sections.items'))
                    ->columnSpan(['lg' => 2])
                    ->components([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(['default' => 2, 'sm' => 4])
                            ->components([
                                TextEntry::make('description')->hiddenLabel()->weight('bold')->columnSpan(2),
                                TextEntry::make('quantity')->hiddenLabel()
                                    ->state(fn (QuoteItem $record): string => FieldFormat::quantity($record->quantity, $record->unit).' × '.FieldFormat::money($record->unit_price_cents)),
                                TextEntry::make('total_cents')->hiddenLabel()->formatStateUsing(fn (int $state): string => FieldFormat::money($state))->alignEnd(),
                            ]),
                        Grid::make(3)->components([
                            TextEntry::make('subtotal')->label(__('field.fields.subtotal'))->state(fn (Quote $record): string => FieldFormat::money($record->subtotalCents())),
                            TextEntry::make('discount_cents')->label(__('field.fields.discount'))->formatStateUsing(fn (int $state): string => FieldFormat::money($state)),
                            TextEntry::make('total_cents')->label(__('field.fields.total'))->formatStateUsing(fn (int $state): string => FieldFormat::money($state))->weight('bold')->size('lg'),
                        ]),
                        TextEntry::make('notes')->label(__('field.fields.quote_notes'))->placeholder('—'),
                        TextEntry::make('internal_notes')->label(__('field.fields.internal_notes'))->placeholder('—'),
                    ]),
                Section::make(__('field.quotes.sections.customer'))
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('customer.name')
                            ->label(__('field.fields.customer'))
                            ->formatStateUsing(fn (Quote $record): string => $record->customer->displayName())
                            ->url(fn (Quote $record): string => CustomerResource::getUrl('view', ['record' => $record->customer_id]))
                            ->color('primary'),
                        TextEntry::make('site')->label(__('field.fields.site'))->state(fn (Quote $record): ?string => $record->site?->address())->placeholder('—'),
                        TextEntry::make('status')
                            ->label(__('field.fields.status'))
                            ->formatStateUsing(fn (Quote $record): string => $record->isExpired() ? __('field.quotes.expired') : $record->status->getLabel())
                            ->color(fn (Quote $record): string => $record->isExpired() ? 'warning' : $record->status->getColor())
                            ->badge(),
                        TextEntry::make('valid_until')->label(__('field.fields.valid_until'))->date(__('app.date_format')),
                        TextEntry::make('viewed_at')->label(__('field.quotes.viewed'))->dateTime(__('app.datetime_format'), OrderResource::timezone())->placeholder(__('field.quotes.not_viewed'))->visible(fn (Quote $record): bool => $record->status !== QuoteStatus::Draft),
                        TextEntry::make('answered')
                            ->label(__('field.quotes.answer'))
                            ->state(fn (Quote $record): ?string => $record->answered_at ? __('field.quotes.answered_by', [
                                'name' => $record->answered_by ?? '—',
                                'at' => $record->answered_at->setTimezone(OrderResource::timezone())->translatedFormat(__('app.datetime_format')),
                                'ip' => $record->answered_ip ?? '—',
                            ]) : null)
                            ->helperText(fn (Quote $record): ?string => $record->decline_reason)
                            ->visible(fn (Quote $record): bool => $record->answered_at !== null),
                        TextEntry::make('order')
                            ->label(__('field.orders.singular_title'))
                            ->state(fn (Quote $record): ?string => $record->order?->label())
                            ->url(fn (Quote $record): ?string => $record->order_id ? OrderResource::getUrl('view', ['record' => $record->order_id]) : null)
                            ->color('primary')
                            ->visible(fn (Quote $record): bool => $record->order_id !== null),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotes::route('/'),
            'create' => Pages\CreateQuote::route('/novo'),
            'view' => Pages\ViewQuote::route('/{record}'),
            'edit' => Pages\EditQuote::route('/{record}/editar'),
        ];
    }
}
