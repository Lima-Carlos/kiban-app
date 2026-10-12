<?php

namespace App\Modules\Fleet\Filament\Vehicles\Pages;

use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\VehicleService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    /**
     * @param  Vehicle  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(VehicleService::class)->update($record, $data);
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return VehicleResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
