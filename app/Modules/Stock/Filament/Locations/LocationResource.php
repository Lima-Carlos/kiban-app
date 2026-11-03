<?php

namespace App\Modules\Stock\Filament\Locations;

use App\Filament\Support\ModuleResource;
use App\Models\User;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\StockModule;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Places where items are kept: warehouses and the stock each technician carries (one per technician).
 */
class LocationResource extends ModuleResource
{
    protected static string $moduleKey = StockModule::KEY;

    protected static ?string $model = Location::class;

    protected static ?string $langGroup = 'stock.locations';

    protected static ?string $slug = 'estoque/locais';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('stock.locations.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stock.locations.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Select::make('kind')->label(__('stock.fields.kind'))
                    ->options([Location::WAREHOUSE => __('stock.locations.kinds.warehouse'), Location::TECHNICIAN => __('stock.locations.kinds.technician')])
                    ->default(Location::WAREHOUSE)->required()->live(),
                Select::make('user_id')->label(__('stock.fields.technician'))
                    ->options(fn (): array => app(TenantContext::class)->get()->users()->orderBy('name')->pluck('users.name', 'users.id')->all())
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('kind') === Location::TECHNICIAN)
                    ->required(fn (Get $get): bool => $get('kind') === Location::TECHNICIAN),
                TextInput::make('name')->label(__('stock.fields.name'))->placeholder(__('stock.helpers.location_name'))->required()->maxLength(120),
                Toggle::make('is_active')->label(__('stock.fields.active'))->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('stock.fields.name'))->weight('bold')->searchable(),
                TextColumn::make('kind')->label(__('stock.fields.kind'))->formatStateUsing(fn (string $state): string => __('stock.locations.kinds.'.$state)),
                TextColumn::make('user.name')->label(__('stock.fields.technician'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('stock.fields.active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading(__('stock.locations.empty'))
            ->emptyStateDescription(__('stock.locations.empty_help'));
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('createLocation', Location::class) ?? false;
    }

    public static function getCreateAuthorizationResponse(): \Illuminate\Auth\Access\Response
    {
        return \Illuminate\Support\Facades\Gate::inspect('createLocation', Location::class);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLocations::route('/')];
    }
}
