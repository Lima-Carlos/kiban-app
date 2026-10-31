<?php

namespace App\Modules\Fleet\Filament\Vehicles\Pages;

use App\Filament\Support\NewRecordTitle;
use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\VehicleService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVehicle extends CreateRecord
{
    use NewRecordTitle;

    protected static string $resource = VehicleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $odometer = (int) ($data['odometer'] ?? 0);
        unset($data['odometer']);

        try {
            return app(VehicleService::class)->register($data, $odometer, auth()->user());
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return VehicleResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
