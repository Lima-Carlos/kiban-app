<?php

namespace App\Modules\Pedidos\Filament\MenuItems;

use App\Filament\Support\ModuleResource;
use App\Modules\Pedidos\Filament\PedidosFields;
use App\Modules\Pedidos\Models\MenuItem;
use App\Modules\Pedidos\PedidosModule;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** The menu (manager): each item with its category, price and whether it is offered now. */
class MenuItemResource extends ModuleResource
{
    protected static string $moduleKey = PedidosModule::KEY;

    protected static ?string $model = MenuItem::class;

    protected static ?string $langGroup = 'pedidos.menu';

    protected static ?string $slug = 'pedidos/cardapio';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('pedidos.menu.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pedidos.menu.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('pedidos.menu.page_title');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('pedidos.menu.page_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'sm' => 2])
            ->components([
                TextInput::make('name')->label(__('pedidos.fields.item_name'))->required()->maxLength(120)->columnSpanFull(),
                TextInput::make('category')->label(__('pedidos.fields.category'))->helperText(__('pedidos.helpers.category'))
                    ->datalist(fn (): array => MenuItem::categories())->maxLength(60),
                PedidosFields::money('price_cents')->label(__('pedidos.fields.price'))->required(),
                Toggle::make('is_active')->label(__('pedidos.fields.offered'))->default(true)->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn ($query) => $query->orderBy('category')->orderBy('sort')->orderBy('name'))
            ->defaultGroup('category')
            ->columns([
                TextColumn::make('name')->label(__('pedidos.fields.item_name'))->weight('bold')->searchable(),
                TextColumn::make('price_cents')->label(__('pedidos.fields.price'))->formatStateUsing(fn (?int $state): string => Money::format($state)),
                ToggleColumn::make('is_active')->label(__('pedidos.fields.offered'))->disabled(fn (MenuItem $record): bool => ! static::canEdit($record)),
            ])
            ->filters([
                SelectFilter::make('category')->label(__('pedidos.fields.category'))->options(fn (): array => array_combine(MenuItem::categories(), MenuItem::categories()) ?: []),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('pedidos.menu.empty'))
            ->emptyStateDescription(__('pedidos.menu.empty_help'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageMenuItems::route('/')];
    }
}
