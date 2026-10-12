<?php

namespace App\Modules\Fleet\Filament;

use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\ModuleRegistry;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Frota settings of the company: units, currency and how early the alerts come. Managers only.
 */
class FleetSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'frota/configuracoes';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->canWrite(FleetModule::KEY) && app(FleetAccess::class)->isManager();
    }

    public static function getNavigationGroup(): ?string
    {
        return app(ModuleRegistry::class)->get(FleetModule::KEY)->name();
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.settings.title');
    }

    public function getTitle(): string
    {
        return __('fleet.settings.title');
    }

    public function mount(): void
    {
        $this->form->fill(FleetSetting::current()->only(['distance_unit', 'volume_unit', 'currency', 'alert_distance', 'alert_days']));
    }

    public function form(Schema $schema): Schema
    {
        $locked = FleetSetting::current()->unitsLocked();

        return $schema
            ->statePath('data')
            ->columns(1)
            ->components([
                Section::make(__('fleet.settings.units'))
                    ->description($locked ? __('fleet.settings.units_locked') : __('fleet.settings.units_help'))
                    ->columns(3)
                    ->components([
                        Select::make('distance_unit')
                            ->label(__('fleet.settings.distance_unit'))
                            ->options(collect(FleetSetting::DISTANCE_UNITS)->mapWithKeys(fn (string $unit): array => [$unit => __('fleet.units.'.$unit)]))
                            ->required()
                            ->disabled($locked),
                        Select::make('volume_unit')
                            ->label(__('fleet.settings.volume_unit'))
                            ->options(collect(FleetSetting::VOLUME_UNITS)->mapWithKeys(fn (string $unit): array => [$unit => __('fleet.units.'.$unit)]))
                            ->required()
                            ->disabled($locked),
                        Select::make('currency')
                            ->label(__('fleet.settings.currency'))
                            ->options(fn (): array => collect(FleetSetting::CURRENCY_CODES)->mapWithKeys(fn (string $code): array => [$code => $code])->all())
                            ->searchable()
                            ->required()
                            ->disabled($locked),
                    ]),
                Section::make(__('fleet.settings.alerts'))
                    ->description(__('fleet.settings.alerts_help'))
                    ->columns(2)
                    ->components([
                        TextInput::make('alert_distance')
                            ->label(__('fleet.settings.alert_distance'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(50_000)
                            ->required(),
                        TextInput::make('alert_days')
                            ->label(__('fleet.settings.alert_days'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(180)
                            ->suffix(__('fleet.settings.days'))
                            ->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('fleet.settings.save'))
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = FleetSetting::current();
        $state = $this->form->getState();

        if ($settings->unitsLocked()) {
            unset($state['distance_unit'], $state['volume_unit'], $state['currency']);
        }

        $settings->update($state);
        Notification::make()->success()->title(__('fleet.notifications.saved'))->send();
    }
}
