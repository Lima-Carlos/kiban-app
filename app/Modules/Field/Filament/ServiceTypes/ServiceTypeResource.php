<?php

namespace App\Modules\Field\Filament\ServiceTypes;

use App\Filament\Support\ModuleResource;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\FieldFields;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Services\FieldFormat;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Types of service (manager): name, color on the agenda, expected time, price and the technician's checklist,
 * i.e. what is filled in on the phone at the customer's place (ticks, yes/no, numbers with unit, texts, one of a
 * list, photos), and whether the customer signs at the end.
 */
class ServiceTypeResource extends ModuleResource
{
    protected static string $moduleKey = FieldModule::KEY;

    protected static ?string $model = ServiceType::class;

    protected static ?string $slug = 'campo/tipos-de-servico';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('field.service_types.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('field.service_types.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('field.service_types.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('field.service_types.singular_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'sm' => 4])->components([
                    TextInput::make('name')
                        ->label(__('field.fields.service_name'))
                        ->placeholder(__('field.helpers.service_name'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(['sm' => 3]),
                    ColorPicker::make('color')
                        ->label(__('field.fields.color'))
                        ->default('#044299'),
                    Textarea::make('description')
                        ->label(__('field.fields.description'))
                        ->rows(2)
                        ->columnSpanFull(),
                    Select::make('duration_minutes')
                        ->label(__('field.fields.duration'))
                        ->helperText(__('field.helpers.duration'))
                        ->options(collect([15, 30, 45, 60, 90, 120, 150, 180, 240, 300, 360, 480])->mapWithKeys(fn (int $minutes): array => [$minutes => FieldFormat::duration($minutes)])->all())
                        ->default(60)
                        ->required()
                        ->columnSpan(['sm' => 2]),
                    FieldFields::price()
                        ->label(__('field.fields.price'))
                        ->helperText(__('field.helpers.price'))
                        ->columnSpan(['sm' => 2]),
                    Toggle::make('signature_required')
                        ->label(__('field.fields.signature_required'))
                        ->helperText(__('field.helpers.signature_required'))
                        ->default(fn (): bool => FieldSetting::current()->signature_required)
                        ->columnSpan(['sm' => 2]),
                    Toggle::make('photo_required')
                        ->label(__('field.fields.photo_required'))
                        ->helperText(__('field.helpers.photo_required'))
                        ->columnSpan(['sm' => 2]),
                ]),
                Section::make(__('field.service_types.checklist'))
                    ->description(__('field.service_types.checklist_help'))
                    ->components([
                        Repeater::make('checklist')
                            ->hiddenLabel()
                            ->addActionLabel(__('field.service_types.add_item'))
                            ->reorderable()
                            ->collapsible()
                            ->collapsed(fn (string $operation): bool => $operation === 'edit')
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->components([
                                Hidden::make('key'),
                                Grid::make(['default' => 1, 'sm' => 6])->components([
                                    TextInput::make('label')
                                        ->label(__('field.fields.question'))
                                        ->placeholder(__('field.helpers.question'))
                                        ->required()
                                        ->maxLength(200)
                                        ->columnSpan(['sm' => 3]),
                                    Select::make('type')
                                        ->label(__('field.fields.answer_type'))
                                        ->options(collect(ServiceType::ITEM_TYPES)->mapWithKeys(fn (string $type): array => [$type => __('field.item_types.'.$type)])->all())
                                        ->default('check')
                                        ->required()
                                        ->live()
                                        ->columnSpan(['sm' => 2]),
                                    Toggle::make('required')
                                        ->label(__('field.fields.required'))
                                        ->inline(false),
                                    TextInput::make('unit')
                                        ->label(__('field.fields.unit'))
                                        ->placeholder('dBm, Mbps, °C...')
                                        ->maxLength(12)
                                        ->visible(fn (Get $get): bool => $get('type') === 'number')
                                        ->columnSpan(['sm' => 2]),
                                    TagsInput::make('options')
                                        ->label(__('field.fields.options'))
                                        ->helperText(__('field.helpers.options'))
                                        ->required(fn (Get $get): bool => $get('type') === 'choice')
                                        ->visible(fn (Get $get): bool => $get('type') === 'choice')
                                        ->columnSpanFull(),
                                ]),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                ColorColumn::make('color')
                    ->label(__('field.fields.color')),
                TextColumn::make('name')
                    ->label(__('field.fields.service_name'))
                    ->description(fn (ServiceType $record): ?string => $record->description)
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('duration_minutes')
                    ->label(__('field.fields.duration'))
                    ->formatStateUsing(fn (int $state): string => FieldFormat::duration($state)),
                TextColumn::make('price_cents')
                    ->label(__('field.fields.price'))
                    ->formatStateUsing(fn (?int $state): string => FieldFormat::money($state))
                    ->placeholder('—'),
                TextColumn::make('checklist')
                    ->label(__('field.service_types.checklist'))
                    ->state(fn (ServiceType $record): string => trans_choice('field.service_types.items', count($record->checklist), ['count' => count($record->checklist)])),
                ToggleColumn::make('is_active')
                    ->label(__('field.fields.active')),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                Action::make('duplicate')
                    ->label(__('field.service_types.duplicate'))
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->authorize(fn (): bool => static::canCreate())
                    ->action(function (ServiceType $record): void {
                        $copy = $record->replicate();
                        $copy->name = __('field.service_types.copy_of', ['name' => $record->name]);
                        $copy->sort = (int) ServiceType::query()->max('sort') + 1;
                        $copy->save();
                    }),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('field.service_types.empty'))
            ->emptyStateDescription(__('field.service_types.empty_help'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageServiceTypes::route('/'),
        ];
    }
}
