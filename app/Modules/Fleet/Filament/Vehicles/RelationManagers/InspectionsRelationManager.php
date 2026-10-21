<?php

namespace App\Modules\Fleet\Filament\Vehicles\RelationManagers;

use App\Modules\Fleet\Filament\Inspections\InspectionResource;
use App\Modules\Fleet\Models\Inspection;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inspections';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.inspections.plural_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('performed_at', 'desc')
            ->columns(InspectionResource::columns(false))
            ->recordActions([
                Action::make('open')
                    ->label(__('fleet.inspections.open'))
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->url(fn (Inspection $record): string => InspectionResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading(__('fleet.inspections.empty'));
    }
}
