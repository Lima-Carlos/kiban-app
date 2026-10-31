<?php

namespace App\Modules\Fleet\Filament\Vehicles\RelationManagers;

use App\Modules\Fleet\Enums\DocumentType;
use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Models\VehicleDocument;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Vehicle documents with expiry date (registration, insurance, tax...) and the file.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    /** Loaded with the page: no empty white box while the tab loads. */
    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.documents.title');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('type')
                    ->label(__('fleet.fields.document_type'))
                    ->options(DocumentType::class)
                    ->required(),
                TextInput::make('number')
                    ->label(__('fleet.fields.number'))
                    ->maxLength(60),
                DatePicker::make('expires_on')
                    ->label(__('fleet.fields.expires_on')),
                FleetFields::file('file_path', 'documents')
                    ->label(__('fleet.fields.file')),
                Textarea::make('notes')
                    ->label(__('fleet.fields.notes'))
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('expires_on')
            ->columns([
                TextColumn::make('type')
                    ->label(__('fleet.fields.document_type'))
                    ->description(fn (VehicleDocument $record): ?string => $record->number),
                TextColumn::make('expires_on')
                    ->label(__('fleet.fields.expires_on'))
                    ->date(__('app.date_format'))
                    ->placeholder('—'),
                TextColumn::make('due')
                    ->label(__('fleet.fields.situation'))
                    ->state(fn (VehicleDocument $record) => $record->dueStatus())
                    ->badge(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('fleet.actions.open_file'))
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->visible(fn (VehicleDocument $record): bool => filled($record->file_path))
                    ->url(fn (VehicleDocument $record): string => Storage::disk('local')->temporaryUrl($record->file_path, now()->addMinutes(10)))
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('fleet.documents.empty'));
    }
}
