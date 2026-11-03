<?php

namespace App\Modules\Stock\Filament\Items;

use App\Filament\Support\ModuleResource;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\Material;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Services\StockFormat;
use App\Modules\Stock\StockModule;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The items kept in stock, with the minimum to warn about and, with the Campo, the material each one is.
 */
class ItemResource extends ModuleResource
{
    protected static string $moduleKey = StockModule::KEY;

    protected static ?string $model = Item::class;

    protected static ?string $langGroup = 'stock.items';

    protected static ?string $slug = 'estoque/itens';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('stock.items.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stock.items.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withSum('balances', 'quantity');
    }

    public static function form(Schema $schema): Schema
    {
        $hasField = fn (): bool => app(TenantContext::class)->get()->license()->hasModule(FieldModule::KEY);

        return $schema
            ->columns(['default' => 1, 'sm' => 2])
            ->components([
                TextInput::make('name')->label(__('stock.fields.name'))->required()->maxLength(160)->columnSpanFull(),
                TextInput::make('sku')->label(__('stock.fields.sku'))->maxLength(60),
                TextInput::make('unit')->label(__('stock.fields.unit'))->datalist(Material::UNITS)->default('un')->required()->maxLength(10),
                TextInput::make('min_quantity')->label(__('stock.fields.min_quantity'))->helperText(__('stock.helpers.min_quantity'))->numeric()->minValue(0)->default(0),
                Toggle::make('serialized')->label(__('stock.fields.serialized'))->helperText(__('stock.helpers.serialized'))->inline(false)
                    ->disabled(fn (?Item $record): bool => $record !== null && $record->balances()->where('quantity', '!=', 0)->exists()),
                Select::make('material_id')->label(__('stock.fields.material'))->helperText(__('stock.helpers.material'))
                    ->options(fn (): array => Material::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible($hasField),
                Toggle::make('is_active')->label(__('stock.fields.active'))->default(true)->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('stock.fields.name'))->weight('bold')->searchable()->description(fn (Item $record): ?string => $record->sku),
                TextColumn::make('balances_sum_quantity')->label(__('stock.fields.total'))
                    ->formatStateUsing(fn ($state, Item $record): string => StockFormat::quantity((float) $state, $record->unit))
                    ->color(fn ($state, Item $record): ?string => $record->min_quantity > 0 && (float) $state < $record->min_quantity ? 'danger' : null),
                TextColumn::make('min_quantity')->label(__('stock.fields.min_quantity'))->formatStateUsing(fn ($state, Item $record): string => StockFormat::quantity((float) $state, $record->unit)),
                IconColumn::make('serialized')->label(__('stock.fields.serialized_short'))->boolean(),
                IconColumn::make('is_active')->label(__('stock.fields.active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading(__('stock.items.empty'))
            ->emptyStateDescription(__('stock.items.empty_help'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageItems::route('/')];
    }
}
