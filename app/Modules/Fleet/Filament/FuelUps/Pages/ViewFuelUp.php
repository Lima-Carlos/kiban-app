<?php

namespace App\Modules\Fleet\Filament\FuelUps\Pages;

use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Filament\FuelUps\FuelUpResource;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\FuelService;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One fuel-up. While open: the big code for the station, "Finish" (volume, amount, receipt) and "Cancel".
 */
class ViewFuelUp extends ViewRecord
{
    protected static string $resource = FuelUpResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var FuelUp $fuelUp */
        $fuelUp = $this->getRecord();

        return __('fleet.fuel.title_for', ['plate' => $fuelUp->vehicle->plate]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->finishAction(),
            $this->cancelAction(),
        ];
    }

    protected function finishAction(): Action
    {
        $settings = FleetSetting::current();

        return Action::make('finish')
            ->label(__('fleet.fuel.finish'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->size('lg')
            ->visible(fn (FuelUp $record): bool => $record->isOpen())
            ->authorize(fn (FuelUp $record): bool => $this->canHandle($record))
            ->modalHeading(__('fleet.fuel.finish'))
            ->modalDescription(__('fleet.fuel.finish_help'))
            ->fillForm(fn (FuelUp $record): array => [
                'full_tank' => true,
                'fuel' => count($accepted = $record->vehicle->fuel_type->acceptedFuels()) === 1 ? $accepted[0]->value : null,
            ])
            ->schema([
                Select::make('fuel')
                    ->label(__('fleet.fields.fuel'))
                    ->options(FuelUpResource::fuelOptions())
                    ->required(),
                TextInput::make('volume')
                    ->label(__('fleet.fields.volume'))
                    ->numeric()
                    ->minValue(0.1)
                    ->maxValue(5000)
                    ->step(0.001)
                    ->suffix($settings->volume_unit)
                    ->required(),
                TextInput::make('total')
                    ->label(__('fleet.fields.total'))
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->prefix($settings->currency)
                    ->required(),
                Toggle::make('full_tank')
                    ->label(__('fleet.fields.full_tank'))
                    ->helperText(__('fleet.fuel.full_tank_help')),
                FleetFields::photo('receipt_path', 'fuel')
                    ->label(__('fleet.fuel.receipt_photo'))
                    ->required(),
            ])
            ->action(function (FuelUp $record, array $data): void {
                try {
                    app(FuelService::class)->complete(
                        $record,
                        (float) $data['volume'],
                        (int) round((float) $data['total'] * 100),
                        (bool) $data['full_tank'],
                        auth()->user(),
                        $data['receipt_path'] ?? null,
                        FuelType::tryFrom((string) $data['fuel']),
                    );
                    Notification::make()->success()->title(__('fleet.fuel.finished'))->send();
                    $this->refreshFormData(['status']);
                } catch (FleetException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    protected function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('fleet.fuel.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (FuelUp $record): bool => $record->isOpen())
            ->authorize(fn (FuelUp $record): bool => $this->canHandle($record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('fleet.fields.reason'))
                    ->required(),
            ])
            ->action(function (FuelUp $record, array $data): void {
                try {
                    app(FuelService::class)->cancel($record, $data['reason'], auth()->user());
                    Notification::make()->success()->title(__('fleet.notifications.saved'))->send();
                } catch (FleetException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    /** Who generated the code, or a manager. */
    protected function canHandle(FuelUp $fuelUp): bool
    {
        if (! app(TenantContext::class)->get()->license()->canWrite(FleetModule::KEY)) {
            return false;
        }

        return $fuelUp->created_by === auth()->id() || app(FleetAccess::class)->isManager();
    }
}
