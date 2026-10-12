<?php

namespace App\Modules\Fleet\Filament\Requests\Pages;

use App\Modules\Fleet\Enums\ServiceKind;
use App\Modules\Fleet\Filament\Requests\ServiceRequestResource;
use App\Modules\Fleet\Filament\RunsFleetRules;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\MaintenanceService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageServiceRequests extends ManageRecords
{
    protected static string $resource = ServiceRequestResource::class;

    public function mount(): void
    {
        parent::mount();

        // Opened from the driver's start page: the form is already open.
        if (request()->boolean('new')) {
            $this->mountAction('request', ['vehicle' => request()->integer('vehicle') ?: null]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            static::requestAction(),
        ];
    }

    public static function requestAction(): Action
    {
        return Action::make('request')
            ->label(__('fleet.requests.create'))
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->authorize('create')
            ->modalHeading(__('fleet.requests.create'))
            ->fillForm(fn (array $arguments): array => ['vehicle_id' => $arguments['vehicle'] ?? null, 'kind' => ServiceKind::Maintenance->value])
            ->schema(ServiceRequestResource::requestForm())
            ->action(fn (array $data) => RunsFleetRules::attempt(
                fn () => app(MaintenanceService::class)->request(
                    Vehicle::query()->whereKey($data['vehicle_id'])->firstOrFail(),
                    ServiceKind::from((string) ($data['kind'] instanceof ServiceKind ? $data['kind']->value : $data['kind'])),
                    (string) $data['description'],
                    auth()->user(),
                    $data['photo_path'] ?? null,
                ),
                __('fleet.requests.sent'),
            ));
    }
}
