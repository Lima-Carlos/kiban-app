<?php

namespace App\Modules\Stock\Filament\Locations\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\Stock\Filament\Locations\LocationResource;
use App\Modules\Stock\Models\Location;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class ManageLocations extends ModuleRecordsPage
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // With the Campo: one place for each technician who still has none ("Vehicle · name").
            Action::make('technicians')
                ->label(__('stock.locations.for_technicians'))
                ->icon(Heroicon::OutlinedTruck)
                ->color('gray')
                ->visible(fn (): bool => app(TenantContext::class)->get()->license()->hasModule(FieldModule::KEY) && LocationResource::canCreate())
                ->requiresConfirmation()
                ->modalDescription(__('stock.locations.for_technicians_help'))
                ->action(function (): void {
                    $created = 0;

                    foreach (app(FieldAccess::class)->technicians() as $technician) {
                        if (! LocationResource::canCreate()) {
                            break;
                        }

                        if (! Location::query()->where('kind', Location::TECHNICIAN)->where('user_id', $technician->getKey())->exists()) {
                            Location::query()->create(['kind' => Location::TECHNICIAN, 'user_id' => $technician->getKey(), 'name' => __('stock.locations.vehicle_of', ['name' => $technician->name])]);
                            $created++;
                        }
                    }

                    Notification::make()->success()->title(trans_choice('stock.locations.created', $created, ['count' => $created]))->send();
                }),
            CreateAction::make(),
        ];
    }
}
