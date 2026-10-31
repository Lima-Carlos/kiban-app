<?php

namespace App\Modules\Fleet\Filament\Maintenances;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\Enums\MaintenanceItem;
use App\Modules\Fleet\Enums\MaintenanceType;
use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Filament\WorkForms;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Maintenance;
use App\Modules\Fleet\Services\FleetFormat;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Maintenance history: what was done, odometer, workshop or mechanic, costs and invoice.
 */
class MaintenanceResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = Maintenance::class;

    protected static ?string $langGroup = 'fleet.maintenances';

    protected static ?string $slug = 'frota/manutencoes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 6;

    public static function getModelLabel(): string
    {
        return __('fleet.maintenances.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.maintenances.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vehicle', 'workshop', 'mechanic']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('performed_on', 'desc')
            ->columns([
                TextColumn::make('performed_on')
                    ->label(__('fleet.fields.performed_on'))
                    ->date(__('app.date_format'))
                    ->sortable(),
                TextColumn::make('vehicle.plate')
                    ->label(__('fleet.fields.vehicle'))
                    ->weight('bold')
                    ->description(fn (Maintenance $record): string => FleetFormat::distance($record->odometer)),
                TextColumn::make('services')
                    ->label(__('fleet.fields.services'))
                    ->state(fn (Maintenance $record): array => array_map(fn (MaintenanceItem $item): string => $item->getLabel(), $record->items()))
                    ->badge()
                    ->description(fn (Maintenance $record): string => $record->type->getLabel()),
                TextColumn::make('workshop.name')
                    ->label(__('fleet.fields.who_did'))
                    ->state(fn (Maintenance $record): ?string => $record->workshop?->name ?? $record->mechanic?->name)
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('total_cents')
                    ->label(__('fleet.fields.total'))
                    ->formatStateUsing(fn (int $state): string => FleetFormat::money($state))
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')->label(__('fleet.fields.vehicle'))->relationship('vehicle', 'plate')->searchable(),
                SelectFilter::make('type')->label(__('fleet.fields.maintenance_type'))->options(MaintenanceType::class),
            ])
            ->recordActions([
                Action::make('invoice')
                    ->label(__('fleet.fields.invoice'))
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->link()
                    ->visible(fn (Maintenance $record): bool => filled($record->invoice_path))
                    ->url(fn (Maintenance $record): string => Storage::disk('local')->temporaryUrl($record->invoice_path, now()->addMinutes(10)))
                    ->openUrlInNewTab(),
                // Only the details can be fixed; odometer and services are history (they moved the odometer and the plan).
                EditAction::make()
                    ->link()
                    ->fillForm(fn (Maintenance $record): array => [
                        'description' => $record->description,
                        'workshop_id' => $record->workshop_id,
                        'performed_by' => $record->performed_by,
                        'parts' => number_format($record->parts_cents / 100, 2, '.', ''),
                        'labor' => number_format($record->labor_cents / 100, 2, '.', ''),
                        'invoice_path' => $record->invoice_path,
                    ])
                    ->schema([
                        Textarea::make('description')->label(__('fleet.fields.what_was_done'))->rows(2),
                        Grid::make(2)->components([
                            WorkForms::workshopSelect(),
                            WorkForms::mechanicSelect('performed_by')->label(__('fleet.fields.mechanic')),
                            WorkForms::money('parts')->label(__('fleet.fields.parts')),
                            WorkForms::money('labor')->label(__('fleet.fields.labor')),
                        ]),
                        FleetFields::file('invoice_path', 'maintenance')->label(__('fleet.fields.invoice')),
                    ])
                    ->using(function (Maintenance $record, array $data): Maintenance {
                        $parts = WorkForms::cents($data['parts'] ?? 0);
                        $labor = WorkForms::cents($data['labor'] ?? 0);
                        $record->fill([
                            'description' => $data['description'] ?? null,
                            'workshop_id' => $data['workshop_id'] ?? null,
                            'performed_by' => $data['performed_by'] ?? null,
                            'parts_cents' => $parts,
                            'labor_cents' => $labor,
                            'invoice_path' => $data['invoice_path'] ?? null,
                        ])->forceFill(['total_cents' => $parts + $labor])->save();

                        return $record;
                    }),
            ])
            ->emptyStateHeading(__('fleet.maintenances.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMaintenances::route('/'),
        ];
    }
}
