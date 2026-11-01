<?php

namespace App\Modules\Agenda\Filament\Packages\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Agenda\Filament\Packages\ClientPackageResource;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\Item;
use App\Modules\Agenda\Models\Package;
use App\Modules\Agenda\Services\AgendaException;
use App\Modules\Agenda\Services\PackageService;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * "Sell package": the sale goes through PackageService (a copy of the package with the numbers of the day).
 */
class ManageClientPackages extends ModuleRecordsPage
{
    protected static string $resource = ClientPackageResource::class;

    public function mount(): void
    {
        parent::mount();

        // Coming from a client's page ("Sell package"): the form opens with the client chosen.
        if (request()->integer('cliente') && ClientPackageResource::canCreate()) {
            $this->mountAction('create');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, CreateAction $action): Model {
                    try {
                        return app(PackageService::class)->sell(
                            Client::query()->findOrFail($data['customer_id']),
                            Package::query()->findOrFail($data['package_id']),
                            filled($data['item_id'] ?? null) ? Item::query()->findOrFail($data['item_id']) : null,
                            auth()->user(),
                        );
                    } catch (AgendaException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $action->halt();
                    }
                }),
        ];
    }
}
