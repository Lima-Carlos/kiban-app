<?php

namespace App\Modules\Fleet\Filament\Checklist;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\ChecklistItem;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * The company's inspection checklist (managers): turn ready items off, mark what is critical, add own items.
 */
class ChecklistItemResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = ChecklistItem::class;

    protected static ?string $langGroup = 'fleet.checklist_items';

    protected static ?string $slug = 'frota/itens-da-vistoria';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 13;

    public static function getModelLabel(): string
    {
        return __('fleet.checklist_items.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.checklist_items.plural');
    }

    /** Menu and title: only the first letter in capitals ("Itens da vistoria"). */
    public static function getTitleCasePluralModelLabel(): string
    {
        return __('fleet.checklist_items.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('fleet.checklist_items.singular_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('label')
                    ->label(__('fleet.fields.name'))
                    ->placeholder(fn (?ChecklistItem $record): ?string => filled($record?->key) ? __('fleet.checklist.'.$record->key) : null)
                    ->helperText(fn (?ChecklistItem $record): ?string => filled($record?->key) ? __('fleet.checklist_items.rename_help') : null)
                    ->required(fn (?ChecklistItem $record): bool => blank($record?->key))
                    ->maxLength(120),
                Toggle::make('is_critical')
                    ->label(__('fleet.checklist_items.critical'))
                    ->helperText(__('fleet.checklist_items.critical_help')),
                Toggle::make('is_active')
                    ->label(__('fleet.checklist_items.active'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('label')
                    ->label(__('fleet.fields.name'))
                    ->state(fn (ChecklistItem $record): string => $record->name())
                    ->weight('bold'),
                ToggleColumn::make('is_critical')
                    ->label(__('fleet.checklist_items.critical')),
                ToggleColumn::make('is_active')
                    ->label(__('fleet.checklist_items.active')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('fleet.checklist_items.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageChecklistItems::route('/'),
        ];
    }
}
