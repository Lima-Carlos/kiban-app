<?php

namespace App\Modules\Fleet\Filament\Inspections\Pages;

use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Filament\Inspections\InspectionResource;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\ChecklistItem;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\FleetFormat;
use App\Modules\Fleet\Services\InspectionService;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * "Do inspection" (phone-first): vehicle and odometer, OK / problem for each item (a problem asks for a
 * note and, if possible, a photo), then the photos of the 4 sides and the dashboard.
 */
class DoInspection extends Page
{
    protected static string $resource = InspectionResource::class;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null
            && $tenant->license()->canWrite(FleetModule::KEY)
            && auth()->check()
            && app(InspectionService::class)->canInspect(auth()->user());
    }

    public function getTitle(): string|Htmlable
    {
        return __('fleet.inspections.do');
    }

    public function mount(): void
    {
        $vehicle = request()->integer('vehicle') ?: null;
        $options = $this->vehicleOptions();

        // Only one vehicle: already selected.
        if ($vehicle === null && count($options) === 1) {
            $vehicle = array_key_first($options);
        }

        $this->form->fill(['vehicle_id' => $vehicle]);
    }

    public function form(Schema $schema): Schema
    {
        $settings = FleetSetting::current();

        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 2])
                    ->components([
                        Select::make('vehicle_id')
                            ->label(__('fleet.fields.vehicle'))
                            ->options(fn (): array => $this->vehicleOptions())
                            ->searchable()
                            ->required()
                            ->live(),
                        TextInput::make('odometer')
                            ->label(__('fleet.fuel.odometer_now'))
                            ->integer()
                            ->required()
                            ->minValue(fn (Get $get): int => (int) Vehicle::query()->whereKey($get('vehicle_id'))->value('odometer'))
                            ->suffix($settings->distance_unit)
                            ->helperText(fn (Get $get): ?string => ($odometer = Vehicle::query()->whereKey($get('vehicle_id'))->value('odometer')) !== null
                                ? __('fleet.fuel.last_odometer', ['value' => FleetFormat::distance((int) $odometer)])
                                : null),
                    ]),
                Section::make(__('fleet.inspections.items'))
                    ->description(__('fleet.inspections.items_help'))
                    ->components(app(InspectionService::class)->items()->map(fn (ChecklistItem $item) => $this->itemFields($item))->all()),
                Section::make(__('fleet.inspections.photos'))
                    ->description(__('fleet.inspections.photos_help'))
                    ->components([
                        Grid::make(['default' => 1, 'sm' => 2])->components(
                            collect(Inspection::PHOTOS)->map(fn (string $photo) => FleetFields::photo('photos.'.$photo, 'inspections')
                                ->label(__('fleet.inspections.photo.'.$photo))
                                ->required())->all(),
                        ),
                    ]),
                Textarea::make('notes')
                    ->label(__('fleet.fields.notes'))
                    ->placeholder(__('fleet.helpers.optional'))
                    ->rows(2),
            ]);
    }

    /** OK / problem; a problem opens the note (required) and the photo (optional). */
    protected function itemFields(ChecklistItem $item): Group
    {
        $id = $item->getKey();
        $problem = fn (Get $get): bool => in_array($get("answers.{$id}.ok"), [false, 0, '0'], true);

        return Group::make([
            ToggleButtons::make("answers.{$id}.ok")
                ->label($item->name())
                ->helperText($item->is_critical ? __('fleet.inspections.critical_item') : null)
                ->boolean(__('fleet.inspections.ok'), __('fleet.inspections.problem'))
                ->inline()
                ->required()
                ->live(),
            Textarea::make("answers.{$id}.note")
                ->label(__('fleet.inspections.what_is_wrong'))
                ->rows(2)
                ->visible($problem)
                ->required($problem),
            FleetFields::photo("answers.{$id}.photo", 'inspections')
                ->label(__('fleet.fields.photo_optional'))
                ->visible($problem),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('finish')
                ->footer([
                    Actions::make([
                        Action::make('finish')
                            ->label(__('fleet.inspections.finish'))
                            ->size('lg')
                            ->submit('finish'),
                    ]),
                ]),
        ]);
    }

    public function finish(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        if (! array_key_exists((int) $data['vehicle_id'], $this->vehicleOptions())) {
            abort(403);
        }

        $vehicle = Vehicle::query()->whereKey($data['vehicle_id'])->firstOrFail();

        try {
            $inspection = app(InspectionService::class)->record($vehicle, [
                'odometer' => (int) $data['odometer'],
                'photos' => (array) ($data['photos'] ?? []),
                'answers' => (array) ($data['answers'] ?? []),
                'notes' => $data['notes'] ?? null,
            ], auth()->user());
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $notification = Notification::make()->title(__('fleet.inspections.saved'));

        if ($inspection->has_critical) {
            $notification->danger()->body(__('fleet.inspections.saved_critical'))->persistent();
        } elseif ($inspection->problems > 0) {
            $notification->warning()->body(__('fleet.inspections.saved_problems'));
        } else {
            $notification->success();
        }

        $notification->send();
        $this->redirect(InspectionResource::getUrl('view', ['record' => $inspection]));
    }

    /**
     * Vehicles this person can inspect (drivers: their own).
     *
     * @return array<int, string>
     */
    protected function vehicleOptions(): array
    {
        $visible = app(FleetAccess::class)->visibleVehicleIds();

        return Vehicle::query()
            ->countingInLimit()
            ->when($visible !== null, fn ($query) => $query->whereKey($visible))
            ->orderBy('plate')
            ->get()
            ->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->getKey() => $vehicle->displayName()])
            ->all();
    }
}
