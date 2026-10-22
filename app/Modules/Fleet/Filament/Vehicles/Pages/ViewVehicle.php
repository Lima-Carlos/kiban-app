<?php

namespace App\Modules\Fleet\Filament\Vehicles\Pages;

use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Enums\VehicleStatus;
use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Rules\UniquePlate;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\OdometerService;
use App\Modules\Fleet\Services\VehicleRecord;
use App\Modules\Fleet\Services\VehicleService;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vehicle page: situation, odometer, next oil change and revision, documents and history.
 */
class ViewVehicle extends ViewRecord
{
    protected static string $resource = VehicleResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Vehicle $vehicle */
        $vehicle = $this->getRecord();

        return $vehicle->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->informOdometerAction(),
            $this->recordAction(),
            EditAction::make(),
            ActionGroup::make([
                $this->changeStatusAction(),
                $this->changeIdentityAction(),
                DeleteAction::make(),
            ]),
        ];
    }

    /** The vehicle record in PDF (managers and viewers: it shows the costs). */
    protected function recordAction(): Action
    {
        return Action::make('record')
            ->label(__('fleet.record.download'))
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->visible(fn (): bool => in_array(app(FleetAccess::class)->fleetRole(), [FleetRole::Manager, FleetRole::Viewer], true))
            ->action(function (Vehicle $record): StreamedResponse {
                $pdf = app(VehicleRecord::class);

                return response()->streamDownload(fn () => print ($pdf->pdf($record)), $pdf->fileName($record), ['Content-Type' => 'application/pdf']);
            });
    }

    /** Manager or a driver of this vehicle types the current odometer. */
    protected function informOdometerAction(): Action
    {
        $settings = FleetSetting::current();

        return Action::make('informOdometer')
            ->label(__('fleet.actions.inform_odometer'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize(fn (Vehicle $record): bool => $this->canRecord($record))
            ->visible(fn (Vehicle $record): bool => $record->isActiveForRecords())
            ->schema([
                TextInput::make('value')
                    ->label(__('fleet.fields.odometer'))
                    ->integer()
                    ->required()
                    ->minValue(fn (Vehicle $record): int => $record->odometer)
                    ->suffix($settings->distance_unit),
                Toggle::make('confirm_jump')
                    ->label(__('fleet.actions.confirm_jump'))
                    ->visible(fn (Vehicle $record, Get $get): bool => (int) $get('value') - $record->odometer > OdometerService::MAX_JUMP),
            ])
            ->action(function (Vehicle $record, array $data): void {
                $this->attempt(fn () => app(OdometerService::class)->record($record, (int) $data['value'], OdometerSource::Manual, user: auth()->user(), confirmedJump: (bool) ($data['confirm_jump'] ?? false)));
            });
    }

    protected function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label(__('fleet.actions.change_status'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->authorize('update')
            ->fillForm(fn (Vehicle $record): array => ['status' => $record->status->value])
            ->schema([
                Select::make('status')
                    ->label(__('fleet.fields.status'))
                    ->options(VehicleStatus::class)
                    ->required()
                    ->live(),
                Textarea::make('reason')
                    ->label(__('fleet.fields.reason'))
                    ->helperText(__('fleet.helpers.deactivate'))
                    ->visible(fn (Get $get): bool => $get('status') === VehicleStatus::Inactive->value || $get('status') === VehicleStatus::Inactive)
                    ->required(fn (Get $get): bool => $get('status') === VehicleStatus::Inactive->value || $get('status') === VehicleStatus::Inactive),
            ])
            ->action(function (Vehicle $record, array $data): void {
                $status = $data['status'] instanceof VehicleStatus ? $data['status'] : VehicleStatus::from($data['status']);
                $this->attempt(fn () => app(VehicleService::class)->setStatus($record, $status, auth()->user(), $data['reason'] ?? null));
            });
    }

    /** After the first record, plate and chassis change only here, with a reason (logged). */
    protected function changeIdentityAction(): Action
    {
        return Action::make('changeIdentity')
            ->label(__('fleet.actions.change_identity'))
            ->icon(Heroicon::OutlinedIdentification)
            ->authorize('update')
            ->visible(fn (Vehicle $record): bool => $record->identity_locked_at !== null)
            ->modalDescription(__('fleet.helpers.change_identity'))
            ->fillForm(fn (Vehicle $record): array => ['plate' => $record->plate, 'vin' => $record->vin])
            ->schema([
                TextInput::make('plate')
                    ->label(__('fleet.fields.plate'))
                    ->required()
                    ->regex('/^[A-Za-z0-9\- ]{2,12}$/')
                    ->rule(fn (Vehicle $record): UniquePlate => new UniquePlate($record)),
                TextInput::make('vin')
                    ->label(__('fleet.fields.vin'))
                    ->maxLength(30),
                Textarea::make('reason')
                    ->label(__('fleet.fields.reason'))
                    ->required(),
            ])
            ->action(function (Vehicle $record, array $data): void {
                $this->attempt(fn () => app(VehicleService::class)->changeIdentity($record, Vehicle::normalizePlate($data['plate']), $data['vin'] ?? null, $data['reason'], auth()->user()));
            });
    }

    protected function canRecord(Vehicle $vehicle): bool
    {
        $access = app(FleetAccess::class);

        if (! app(TenantContext::class)->get()->license()->canWrite(FleetModule::KEY)) {
            return false;
        }

        return $access->isManager() || in_array($vehicle->getKey(), $access->visibleVehicleIds() ?? [], true);
    }

    /**
     * Runs a Frota rule and shows its message when it is refused.
     */
    protected function attempt(callable $callback): void
    {
        try {
            $callback();
            Notification::make()->success()->title(__('fleet.notifications.saved'))->send();
            $this->refreshFormData(['status', 'odometer', 'plate', 'vin']);
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }
}
