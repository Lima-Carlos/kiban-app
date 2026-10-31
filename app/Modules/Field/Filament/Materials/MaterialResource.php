<?php

namespace App\Modules\Field\Filament\Materials;

use App\Filament\Support\ModuleResource;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\FieldFields;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Services\FieldFormat;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Catalog of materials (manager): name, code in the company's stock, unit and price. The technician writes down
 * what was used on each work order (part 4) and the quotes take the prices from here (part 2b).
 */
class MaterialResource extends ModuleResource
{
    protected static string $moduleKey = FieldModule::KEY;

    protected static ?string $model = Material::class;

    protected static ?string $langGroup = 'field.materials';

    protected static ?string $slug = 'campo/materiais';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('field.materials.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('field.materials.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('field.materials.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('field.materials.singular_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'sm' => 2])->components([
                    TextInput::make('name')
                        ->label(__('field.fields.material_name'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('code')
                        ->label(__('field.fields.material_code'))
                        ->helperText(__('field.helpers.material_code'))
                        ->maxLength(60),
                    Select::make('unit')
                        ->label(__('field.fields.unit'))
                        ->options(collect(Material::UNITS)->mapWithKeys(fn (string $unit): array => [$unit => __('field.units.'.$unit)])->all())
                        ->default('un')
                        ->required(),
                    FieldFields::price()
                        ->label(__('field.fields.unit_price')),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('field.fields.material_name'))
                    ->description(fn (Material $record): ?string => $record->code ? '#'.$record->code : null)
                    ->weight('bold')
                    ->searchable(['name', 'code']),
                TextColumn::make('unit')
                    ->label(__('field.fields.unit'))
                    ->formatStateUsing(fn (string $state): string => __('field.units.'.$state)),
                TextColumn::make('price_cents')
                    ->label(__('field.fields.unit_price'))
                    ->formatStateUsing(fn (?int $state): string => FieldFormat::money($state))
                    ->placeholder('—')
                    ->alignEnd(),
                ToggleColumn::make('is_active')
                    ->label(__('field.fields.active')),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('field.fields.active'))
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('field.materials.empty'))
            ->emptyStateDescription(__('field.materials.empty_help'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMaterials::route('/'),
        ];
    }
}
