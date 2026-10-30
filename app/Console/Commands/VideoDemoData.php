<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\Workshop;
use App\Modules\Fleet\Services\FuelService;
use App\Modules\Fleet\Services\MaintenanceService;
use App\Modules\Fleet\Services\VehicleService;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\HolidayCalendar;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Modules\TimeClock\Services\PhotoNoticeService;
use App\Modules\TimeClock\Services\PunchPhotoStore;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use App\People\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * The company used only to record the videos (only on the PC): "Rede Exemplo Fibra" ("rede-exemplo", created
 * first by kiban:video-demo in the Core). Erases and refills only this company, never the owner's test company.
 * Clean, good-looking data: four vans and pickups, a field team that clocks in at the base every day (no flags),
 * accounts in Portuguese, Spanish and English, and the previous month closed.
 */
class VideoDemoData extends ResetDemoData
{
    protected $signature = 'kiban:video-demo {--yes : Do not ask for confirmation}';

    protected $description = 'Erase and refill only the company used to record the videos (only on the PC)';

    public const SUBDOMAIN = 'rede-exemplo';

    protected const NOT_FOUND = 'A empresa "rede-exemplo" não foi encontrada no Core. Rode "php artisan kiban:video-demo" no Core primeiro.';

    protected const READY = 'Empresa dos vídeos pronta: Rede Exemplo, com Frota e Ponto e dados de exemplo.';

    /** The base in Belo Horizonte (Avenida Afonso Pena); the recordings clock in from here. */
    public const BASE = ['latitude' => -19.9227, 'longitude' => -43.9385];

    /** Who clocks in: e-mail (with a login) or name (field staff without a login) => job title. */
    protected const CLOCKS_IN = [
        'tecnico.video@exemplo.test' => 'Técnico de instalação',
        'tecnica.video@exemplo.test' => 'Técnica de manutenção',
        'empleado.video@exemplo.test' => 'Técnica de campo',
        'employee.video@exemplo.test' => 'Técnico de campo',
        'Pedro Rocha' => 'Técnico de instalação',
        'Carla Mendes' => 'Assistente de suporte',
        'Bruno Dias' => 'Técnico de fibra',
        'Fernanda Lopes' => 'Auxiliar administrativo',
    ];

    /** Only this company goes: deleting the tenant removes all its records (cascade) and its files. */
    protected function erase(): void
    {
        $tenant = Tenant::query()->where('subdomain', static::SUBDOMAIN)->first();

        if ($tenant !== null) {
            Storage::disk('local')->deleteDirectory('tenants/'.$tenant->getKey());
            $tenant->delete();
        }
    }

    /**
     * @param  array<string, User>  $users  by e-mail
     */
    protected function fill(Tenant $tenant, array $users): void
    {
        $owner = $users['dona.video@exemplo.test'];
        $people = app(PeopleService::class);
        $person = fn (string $email): Person => Person::query()->where('user_id', $users[$email]->getKey())->sole();
        $start = $this->today->subDays(35);

        // --- Frota: four vehicles of the field team, a station, a workshop, the fleet manager and a driver.
        $this->at($start->setTime(9, 0));
        $vehicles = [
            app(VehicleService::class)->register(['plate' => 'REX1A23', 'brand' => 'Fiat', 'model' => 'Strada Freedom', 'year' => 2024, 'type' => 'pickup', 'fuel_type' => 'flex', 'tank_capacity' => 55], 18420, $owner),
            app(VehicleService::class)->register(['plate' => 'REX2B34', 'brand' => 'Renault', 'model' => 'Kangoo', 'year' => 2023, 'type' => 'van', 'fuel_type' => 'flex', 'tank_capacity' => 60], 35210, $owner),
            app(VehicleService::class)->register(['plate' => 'REX3C45', 'brand' => 'Fiat', 'model' => 'Fiorino', 'year' => 2024, 'type' => 'van', 'fuel_type' => 'flex', 'tank_capacity' => 50], 22870, $owner),
            app(VehicleService::class)->register(['plate' => 'REX4D56', 'brand' => 'Volkswagen', 'model' => 'Saveiro', 'year' => 2022, 'type' => 'pickup', 'fuel_type' => 'flex', 'tank_capacity' => 55], 47930, $owner),
        ];
        $station = FuelStation::create(['name' => 'Posto Avenida Central', 'city' => 'Belo Horizonte']);
        $workshop = Workshop::create(['name' => 'Auto Center Savassi', 'city' => 'Belo Horizonte', 'phone' => '3132221100']);

        $people->saveModules($person('frota.video@exemplo.test'), ['frota' => ['role' => 'manager']]);
        $people->saveModules($person('tecnico.video@exemplo.test'), ['frota' => [
            'role' => 'driver', 'vehicles' => [$vehicles[0]->getKey(), $vehicles[2]->getKey()],
            'license_number' => '04512378900', 'license_category' => 'B', 'license_expires_on' => $start->addYears(4)->toDateString(),
        ]]);
        $driverId = Driver::query()->where('user_id', $users['tecnico.video@exemplo.test']->getKey())->value('id');

        foreach ([28, 21, 14, 7, 2] as $round => $daysAgo) {
            foreach ([[0, 390, 36.2, 21800], [1, 420, 41.5, 24900], [2, 360, 33.8, 20300], [3, 300, 30.1, 18200]] as [$index, $km, $liters, $cents]) {
                $vehicle = $vehicles[$index];
                $this->at($this->today->subDays($daysAgo)->setTime(7 + $index, 10 + $round * 3));
                app(FuelService::class)->register([
                    'vehicle' => $vehicle, 'station' => $station, 'driver_id' => in_array($index, [0, 2], true) ? $driverId : null,
                    'odometer' => $vehicle->fresh()->odometer + $km + $round * 5, 'volume' => $liters + $round * 0.5, 'total_cents' => $cents + $round * 300,
                    'full_tank' => true, 'fueled_at' => now(),
                ], $owner);
            }
        }

        $this->at($this->today->subDays(9)->setTime(14, 30));
        app(MaintenanceService::class)->recordMaintenance($vehicles[1], [
            'type' => 'preventive', 'services' => ['oil_change', 'filters'], 'performed_on' => now()->toDateString(),
            'odometer' => $vehicles[1]->fresh()->odometer + 40, 'workshop_id' => $workshop->getKey(),
            'parts_cents' => 31500, 'labor_cents' => 14000, 'description' => 'Revisão dos 35 mil km',
        ], $owner);

        // --- Ponto: rules, the base, the office schedule, HR and the field team.
        $this->at($start->setTime(9, 30));
        TimeClockSetting::current()->forceFill(['configured_at' => now()])->save();
        $base = Workplace::create([
            'name' => 'Base Centro', 'address' => 'Avenida Afonso Pena, 1500', 'city' => 'Belo Horizonte', 'state' => 'MG',
            'timezone' => self::TZ, 'latitude' => self::BASE['latitude'], 'longitude' => self::BASE['longitude'], 'radius_meters' => 300,
        ]);
        $schedule = Schedule::create(['name' => 'Comercial (8h às 18h)'] + ScheduleService::presets()['office']);
        $people->saveModules($person('rh.video@exemplo.test'), ['ponto' => ['role' => 'manager']]);

        $employees = [];
        $number = 0;
        foreach (self::CLOCKS_IN as $who => $job) {
            $number++;
            $ponto = [
                'clocks_in' => true, 'admitted_on' => $this->today->startOfMonth()->subMonth()->toDateString(), 'job_title' => $job,
                'registration' => str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'workplace_id' => $base->getKey(), 'schedule_id' => $schedule->getKey(),
            ];

            if (str_contains($who, '@')) {
                $record = $person($who);
                $record->setDocument($this->cpf(31415926 + $number));
                $record->save();
                $people->saveModules($record, ['ponto' => $ponto]);
                $employee = Employee::query()->where('person_id', $record->getKey())->sole();
                app(PhotoNoticeService::class)->record($employee, 'phone', $users[$who]);
            } else {
                $record = $people->add($tenant, ['name' => $who, 'document' => $this->cpf(27182818 + $number)], ['ponto' => $ponto], $owner, false)['person'];
                $employee = Employee::query()->where('person_id', $record->getKey())->sole();
            }

            $employees[$who] = $employee;
        }

        $this->teamPunches($employees, $base, $users['rh.video@exemplo.test']);
        $this->closeMonth($users);
    }

    /**
     * Every working day since the hiring, inside the base, with small natural variations and no forgotten punch.
     * Today: the team already started, except Ana and the Spanish and English accounts (the videos clock them in).
     *
     * @param  array<string, Employee>  $employees
     */
    protected function teamPunches(array $employees, Workplace $base, User $hr): void
    {
        $holidays = app(HolidayCalendar::class);
        $offset = 0;

        foreach ($employees as $who => $employee) {
            $offset++;

            for ($day = $this->today->startOfMonth()->subMonth(), $position = 0; $day->lt($this->today); $day = $day->addDay()) {
                if (! $day->isWeekday() || $holidays->holidayOn($day) !== null) {
                    continue;
                }

                $position++;
                $lunch = ($position * 2 + $offset) % 6;
                foreach (['07:5'.(($position + $offset) % 10), '12:0'.$lunch, '13:0'.($lunch + 1), '18:0'.(($position * 3 + $offset) % 10)] as $time) {
                    $this->clockIn($employee, $day, $time, $base, $hr);
                }
            }

            $startsToday = ! in_array($who, ['tecnica.video@exemplo.test', 'empleado.video@exemplo.test', 'employee.video@exemplo.test'], true);
            if ($startsToday && $this->today->isWeekday() && $this->realNow()->format('H:i') > '08:05') {
                $this->clockIn($employee, $this->today, '07:5'.($offset % 10), $base, $hr);
            }
        }
    }

    protected function clockIn(Employee $employee, CarbonImmutable $day, string $time, Workplace $base, User $hr): void
    {
        $this->at(CarbonImmutable::parse($day->toDateString().' '.$time, self::TZ));
        $user = $employee->person->user ?? $hr;

        app(PunchService::class)->punch($employee, [
            'latitude' => $base->latitude + 0.0002, 'longitude' => $base->longitude - 0.0001, 'accuracy' => 10,
            'photo_path' => app(PunchPhotoStore::class)->fromDataUrl($this->photo($employee->person->name)),
            'device' => 'Dados de demonstração',
        ], $user);
    }

    /**
     * @param  array<string, User>  $users
     */
    protected function closeMonth(array $users): void
    {
        $this->realNow();
        $month = $this->today->startOfMonth()->subMonth();
        $closing = app(MonthClosing::class);

        if (! $closing->canClose($month, self::TZ)) {
            return;
        }

        $closing->closeAll($month, $users['dona.video@exemplo.test']);
        $employee = Employee::query()->whereHas('person', fn ($query) => $query->where('user_id', $users['tecnico.video@exemplo.test']->getKey()))->sole();

        if (($signed = $closing->current($employee, $month)) !== null) {
            $closing->sign($signed, $users['tecnico.video@exemplo.test'], '127.0.0.1', 'Dados de demonstração');
        }
    }

    /** A valid, made-up CPF built from 8 digits (the check digits are calculated). */
    protected function cpf(int $seed): string
    {
        $digits = str_split(str_pad((string) ($seed % 1000000000), 9, '1', STR_PAD_LEFT));

        foreach ([10, 11] as $weight) {
            $sum = 0;
            foreach ($digits as $i => $digit) {
                $sum += (int) $digit * ($weight - $i);
            }
            $digits[] = (string) ((($sum * 10) % 11) % 10);
        }

        return implode('', $digits);
    }
}
