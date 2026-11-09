<?php

namespace Tests\Feature;

use App\Filament\Pages\PeoplePage;
use App\Models\Team;
use App\Models\Tenant;
use App\Modules\Fleet\Filament\Vehicles\Pages\ListVehicles;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\VehicleImporter;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\Person;
use App\People\PersonImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Changing system without typing: employees (with the Ponto data) and vehicles from a spreadsheet, each line
 * checked like the form, wrong lines skipped with the reason, nothing repeated.
 */
class SpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('instaladora', ['ponto' => [], 'frota' => []]);
        $this->signIn($this->company);
    }

    /** @param  array<int, array<int, string>>  $rows */
    protected function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kb').'.csv';
        file_put_contents($path, implode("\n", array_map(fn (array $row): string => implode(';', $row), $rows))."\n");

        return $path;
    }

    public function test_employees_with_the_time_clock(): void
    {
        Workplace::create(['name' => 'Matriz', 'timezone' => 'America/Sao_Paulo']);
        Schedule::create(['name' => 'Comercial'] + ScheduleService::presets()['office'] + ['anchor_date' => '2026-11-02']);
        Person::create(['name' => 'Carla Antiga', 'email' => 'carla@exemplo.com']);

        $path = $this->csv([
            ['Nome', 'Celular', 'E-mail', 'CPF', 'Equipe', 'Bate ponto', 'Matrícula', 'Admissão', 'Local de trabalho', 'Horário', 'Coluna estranha'],
            ['Ana Souza', '11988887777', 'ana@exemplo.com', '529.982.247-25', 'Instalação', 'sim', '12', '01/03/2026', 'Matriz', 'Comercial', 'x'],
            ['Bruno Dias', '11977776666', '', '111.444.777-35', 'Instalação', 'sim', '13', '02/03/2026', 'externo', 'comercial', ''],
            ['Caio Lima', '', '', '', '', '', '', '', '', '', ''],
            ['Carla Antiga', '', 'carla@exemplo.com', '', '', '', '', '', '', '', ''],
            ['Davi Erro', '', '', '111.111.111-11', '', '', '', '', '', '', ''],
            ['Eva Sem Local', '', '', '', '', 'sim', '', '01/03/2026', 'Filial', 'Comercial', ''],
            ['', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $result = app(PersonImporter::class)->import($path, 'csv');

        $this->assertSame([3, 0], [$result['created'], $result['updated']]);
        $this->assertSame([5, 6, 7], array_column($result['errors'], 'line'));
        $this->assertSame(__('app.people.import.already', ['name' => 'Carla Antiga']), $result['errors'][0]['message']);
        $this->assertSame(__('app.people.import.invalid_cpf'), $result['errors'][1]['message']);
        $this->assertSame(__('app.people.import.workplace', ['name' => 'Filial']), $result['errors'][2]['message']);

        $ana = Person::query()->where('name', 'Ana Souza')->sole();
        $this->assertSame(['52998224725', 'Instalação', null], [$ana->document, $ana->team?->name, $ana->user_id]);
        $this->assertSame(1, Team::count());
        $employee = Employee::query()->where('person_id', $ana->id)->sole();
        $this->assertSame(['12', '2026-03-01', 'Matriz'], [$employee->registration, $employee->admitted_on->toDateString(), $employee->workplace->name]);
        $this->assertTrue(Employee::query()->whereHas('person', fn ($query) => $query->where('name', 'Bruno Dias'))->sole()->is_external);
        $this->assertSame(0, Employee::query()->whereHas('person', fn ($query) => $query->where('name', 'Caio Lima'))->count());
    }

    public function test_vehicles(): void
    {
        $path = $this->csv([
            ['Placa', 'Marca', 'Modelo', 'Ano', 'Tipo', 'Combustível', 'KM'],
            ['abc-1d23', 'Fiat', 'Strada', '2024', 'picape', 'flex', '35200'],
            ['XYZ9876', 'VW', 'Saveiro', '2023', 'carro', 'gasolina', '10000'],
            ['QWE1234', '', '', '', '', '', ''],
            ['!!', 'Fiat', 'Uno', '', '', '', ''],
        ]);

        $result = app(VehicleImporter::class)->import($path, 'csv');

        $this->assertSame([2, 0], [$result['created'], $result['updated']]);
        $this->assertSame([4, 5], array_column($result['errors'], 'line'));
        $strada = Vehicle::query()->where('plate', 'ABC1D23')->sole();
        $this->assertSame(['pickup', 'flex', 35200, 2024], [$strada->type->value, $strada->fuel_type->value, $strada->odometer, (int) $strada->manufacture_year]);

        // The same plate again: the data is updated, the odometer stays.
        $again = app(VehicleImporter::class)->import($this->csv([['Placa', 'Cor', 'KM'], ['ABC1D23', 'Branco', '99999']]), 'csv');
        $this->assertSame([0, 1], [$again['created'], $again['updated']]);
        $this->assertSame(['Branco', 35200], [$strada->fresh()->color, $strada->fresh()->odometer]);
    }

    public function test_the_buttons_and_the_models(): void
    {
        Livewire::test(PeoplePage::class)->assertActionVisible('importTemplate');
        Livewire::test(ListVehicles::class)->assertActionVisible('importSpreadsheet');

        $this->assertFileExists(app(PersonImporter::class)->template());
        $this->assertFileExists(app(VehicleImporter::class)->template());
    }
}
