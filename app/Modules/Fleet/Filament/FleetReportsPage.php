<?php

namespace App\Modules\Fleet\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetExporter;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel spreadsheets of the Frota (managers and viewers): fuel-ups, costs, maintenance and inspections,
 * for a period and all vehicles or one.
 */
class FleetReportsPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = FleetModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'frota/relatorios';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null
            && $tenant->license()->hasModule(FleetModule::KEY)
            && in_array(app(FleetAccess::class)->fleetRole(), [FleetRole::Manager, FleetRole::Viewer], true);
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.reports.title');
    }

    public function getTitle(): string
    {
        return __('fleet.reports.title');
    }

    public function getSubheading(): ?string
    {
        return __('fleet.reports.help');
    }

    public function mount(): void
    {
        $this->form->fill([
            'report' => 'costs',
            'from' => now(FleetFields::timezone())->startOfMonth()->subMonths(2)->toDateString(),
            'to' => now(FleetFields::timezone())->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Radio::make('report')
                    ->label(__('fleet.reports.which'))
                    ->options(collect(FleetExporter::REPORTS)->mapWithKeys(fn (string $report): array => [$report => __("fleet.reports.type.{$report}")])->all())
                    ->descriptions(collect(FleetExporter::REPORTS)->mapWithKeys(fn (string $report): array => [$report => __("fleet.reports.type_help.{$report}")])->all())
                    ->required(),
                Grid::make(['default' => 1, 'md' => 3])->components([
                    DatePicker::make('from')->label(__('fleet.reports.from'))->required()->native(false)->displayFormat(__('app.date_format')),
                    DatePicker::make('to')->label(__('fleet.reports.to'))->required()->afterOrEqual('from')->native(false)->displayFormat(__('app.date_format')),
                    Select::make('vehicle_id')
                        ->label(__('fleet.fields.vehicle'))
                        ->placeholder(__('fleet.reports.all_vehicles'))
                        ->options(fn (): array => Vehicle::query()->orderBy('plate')->get()->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->getKey() => $vehicle->displayName()])->all())
                        ->searchable(),
                ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('download')
                ->footer([
                    Actions::make([
                        Action::make('download')
                            ->label(__('fleet.reports.download'))
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->submit('download'),
                    ]),
                ]),
        ]);
    }

    public function download(): BinaryFileResponse
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $path = app(FleetExporter::class)->export($data['report'], (string) $data['from'], (string) $data['to'], filled($data['vehicle_id'] ?? null) ? (int) $data['vehicle_id'] : null);

        return response()->download($path, basename(preg_replace('/-[A-Za-z0-9]{6}\.xlsx$/', '.xlsx', $path)))->deleteFileAfterSend();
    }
}
