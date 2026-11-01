<?php

namespace App\Modules\Agenda\Filament\Packages;

use App\Filament\Support\ModuleResource;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Filament\AgendaFields;
use App\Modules\Agenda\Models\Package;
use App\Modules\Agenda\Models\Service;
use App\Modules\Agenda\Services\AgendaFormat;
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
use Filament\Tables\Table;

/**
 * Packages for sale (manager): a service, how many sessions, how many days it is valid and the price.
 */
class PackageResource extends ModuleResource
{
    protected static string $moduleKey = AgendaModule::KEY;

    protected static ?string $model = Package::class;

    protected static ?string $langGroup = 'agenda.packages';

    protected static ?string $slug = 'agenda/pacotes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 50;

    public static function getModelLabel(): string
    {
        return __('agenda.packages.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('agenda.packages.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('agenda.packages.plural_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(['default' => 1, 'sm' => 2])->components([
                TextInput::make('name')->label(__('agenda.fields.package_name'))->required()->maxLength(120)->columnSpanFull(),
                Select::make('service_id')->label(__('agenda.fields.service'))
                    ->options(fn (): array => Service::query()->active()->where('is_pickup', false)->orderBy('sort')->pluck('name', 'id')->all())
                    ->required(),
                TextInput::make('quantity')->label(__('agenda.fields.quantity'))->numeric()->integer()->minValue(1)->maxValue(100)->required(),
                TextInput::make('valid_days')->label(__('agenda.fields.valid_days'))->numeric()->integer()->minValue(1)->maxValue(730)->suffix(__('agenda.fields.days'))->default(60)->required(),
                AgendaFields::price()->label(__('agenda.fields.price')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('agenda.fields.package_name'))->weight('bold')
                    ->description(fn (Package $record): string => __('agenda.packages.summary', ['quantity' => $record->quantity, 'service' => $record->service?->name, 'days' => $record->valid_days])),
                TextColumn::make('price_cents')->label(__('agenda.fields.price'))->formatStateUsing(fn (?int $state): string => AgendaFormat::money($state))->placeholder('—')->alignEnd(),
                ToggleColumn::make('is_active')->label(__('agenda.fields.active')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading(__('agenda.packages.empty'))
            ->emptyStateDescription(__('agenda.packages.empty_help'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePackages::route('/')];
    }
}
