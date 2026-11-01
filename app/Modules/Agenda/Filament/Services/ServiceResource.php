<?php

namespace App\Modules\Agenda\Filament\Services;

use App\Filament\Support\ModuleResource;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Agenda\Filament\AgendaFields;
use App\Modules\Agenda\Models\Service;
use App\Modules\Agenda\Services\AgendaFormat;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Services of the company (manager): time and price, optionally per size (P, M, G, GG); add-ons (only with a main
 * service); the pick-up ("leva e traz"); whether it shows on the public booking page.
 */
class ServiceResource extends ModuleResource
{
    protected static string $moduleKey = AgendaModule::KEY;

    protected static ?string $model = Service::class;

    protected static ?string $langGroup = 'agenda.services';

    protected static ?string $slug = 'agenda/servicos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('agenda.services.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('agenda.services.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('agenda.services.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('agenda.services.singular_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'sm' => 2])->components([
                    TextInput::make('name')
                        ->label(__('agenda.fields.service_name'))
                        ->required()
                        ->maxLength(120)
                        ->columnSpanFull(),
                    TextInput::make('duration_minutes')
                        ->label(__('agenda.fields.duration'))
                        ->helperText(__('agenda.helpers.duration'))
                        ->numeric()->integer()->minValue(0)->maxValue(720)
                        ->suffix('min')
                        ->default(60)
                        ->required(),
                    AgendaFields::price()
                        ->label(__('agenda.fields.price')),
                    Toggle::make('is_addon')
                        ->label(__('agenda.fields.is_addon'))
                        ->helperText(__('agenda.helpers.is_addon')),
                    Toggle::make('is_pickup')
                        ->label(__('agenda.fields.is_pickup'))
                        ->helperText(__('agenda.helpers.is_pickup')),
                    Toggle::make('online')
                        ->label(__('agenda.fields.online'))
                        ->helperText(__('agenda.helpers.online'))
                        ->default(true),
                    ColorPicker::make('color')
                        ->label(__('agenda.fields.color')),
                    Textarea::make('description')
                        ->label(__('agenda.fields.description'))
                        ->helperText(__('agenda.helpers.description'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
                Section::make(__('agenda.services.by_size'))
                    ->description(__('agenda.services.by_size_help'))
                    ->compact()
                    ->collapsible()
                    ->columns(['default' => 2, 'md' => 4])
                    ->components(collect(ItemSize::cases())->flatMap(fn (ItemSize $size): array => [
                        AgendaFields::price('size_prices.'.$size->value.'.price_cents')
                            ->label(__('agenda.services.size_price', ['size' => $size->short()])),
                        TextInput::make('size_prices.'.$size->value.'.duration_minutes')
                            ->label(__('agenda.services.size_duration', ['size' => $size->short()]))
                            ->numeric()->integer()->minValue(0)->maxValue(720)
                            ->suffix('min'),
                    ])->all()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')
                    ->label(__('agenda.fields.service_name'))
                    ->weight('bold')
                    ->description(fn (Service $record): ?string => match (true) {
                        $record->is_pickup => __('agenda.fields.is_pickup'),
                        $record->is_addon => __('agenda.fields.is_addon'),
                        default => null,
                    })
                    ->searchable(),
                TextColumn::make('duration_minutes')
                    ->label(__('agenda.fields.duration'))
                    ->formatStateUsing(fn (Service $record): string => static::range($record, fn (?ItemSize $size): string => AgendaFormat::duration($record->durationFor($size)))),
                TextColumn::make('price_cents')
                    ->label(__('agenda.fields.price'))
                    ->state(fn (Service $record): string => static::range($record, fn (?ItemSize $size): string => AgendaFormat::money($record->priceFor($size))))
                    ->alignEnd(),
                ToggleColumn::make('online')
                    ->label(__('agenda.fields.online'))
                    ->visibleFrom('md'),
                ToggleColumn::make('is_active')
                    ->label(__('agenda.fields.active')),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('agenda.fields.active'))
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('agenda.services.empty'))
            ->emptyStateDescription(__('agenda.services.empty_help'));
    }

    /** "45 min – 2 h" or "R$ 50,00 – R$ 110,00" when it changes with the size. */
    protected static function range(Service $service, callable $value): string
    {
        if (! $service->variesBySize()) {
            return $value(null);
        }

        $values = collect(ItemSize::cases())->map(fn (ItemSize $size): string => $value($size))->unique()->values();

        return $values->count() > 1 ? $values->first().' – '.$values->last() : $values->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageServices::route('/'),
        ];
    }
}
