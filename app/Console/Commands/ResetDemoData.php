<?php

namespace App\Console\Commands;

use App\Auth\CoreSignIn;
use App\Licensing\CoreClient;
use App\Licensing\CoreUnavailableException;
use App\Licensing\LicenseManager;
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
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Test data from zero (only on the PC): erases every company, person, record and file of the app and creates the
 * data of the demonstration company of the Core ("transportes-demo", created first by kiban:demo in the Core):
 * the roles of each account in Frota and Ponto, 3 vehicles with fuel-ups and a maintenance, a workplace, a
 * schedule, the punches since the 1st of the previous month and that month closed (one time sheet signed). Run both with "dados-demo" (in the kiban-core folder).
 */
class ResetDemoData extends Command
{
    protected $signature = 'kiban:demo {--yes : Do not ask for confirmation}';

    protected $description = 'Erase the test data and fill the demonstration company (only on the PC)';

    public const SUBDOMAIN = 'transportes-demo';

    /** Tables of the framework itself (never data of a company). */
    protected const KEEP = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    protected const TZ = 'America/Sao_Paulo';

    /** The real today, taken once before any demo moment is set (every date counts from it). */
    protected CarbonImmutable $today;

    public function handle(LicenseManager $licenses, CoreClient $core, CoreSignIn $signIn, TenantContext $context): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('Este comando apaga dados e só roda no computador de desenvolvimento.');

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm('Apagar TODOS os dados de teste dos sistemas e criar os dados de demonstração?')) {
            return self::FAILURE;
        }

        $this->today = CarbonImmutable::now(self::TZ)->startOfDay();
        $this->erase();

        try {
            $tenant = $licenses->resolve(self::SUBDOMAIN);
            $members = $tenant ? $core->members(self::SUBDOMAIN) : [];
        } catch (CoreUnavailableException) {
            $tenant = null;
        }

        if ($tenant === null || $members === []) {
            $this->components->error('A empresa "transportes-demo" não foi encontrada no Core. Ligue o Core e rode "dados-demo" (ou "php artisan kiban:demo" no Core primeiro).');

            return self::FAILURE;
        }

        try {
            $context->run($tenant, function (Tenant $tenant) use ($members, $signIn): void {
                $users = collect($members)->mapWithKeys(fn (array $member): array => [(string) $member['email'] => $signIn->syncMember($member, $tenant)]);
                app(PeopleService::class)->ensureRecords($tenant);
                $this->fill($tenant, $users->all());
            });
        } finally {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }

        $this->components->info('Sistemas prontos: Transportes Demo, com Frota e Ponto e dados de exemplo.');

        return self::SUCCESS;
    }

    protected function erase(): void
    {
        $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public'"))
            ->pluck('tablename')
            ->reject(fn (string $table): bool => in_array($table, self::KEEP, true))
            ->all();

        DB::statement('TRUNCATE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
        Storage::disk('local')->deleteDirectory('tenants');
        Storage::disk('local')->deleteDirectory('exports');
        Cache::flush();
    }

    /**
     * @param  array<string, User>  $users  by e-mail
     */
    protected function fill(Tenant $tenant, array $users): void
    {
        $owner = $users['dono.demo@exemplo.test'];
        $people = app(PeopleService::class);
        $person = fn (string $email): Person => Person::query()->where('user_id', $users[$email]->getKey())->sole();
        $start = $this->today->subDays(35);

        // --- Frota: 3 vehicles (registered 5 weeks ago), a station, a workshop, the driver and the manager.
        $this->at($start->setTime(9, 0));
        $vehicles = [
            app(VehicleService::class)->register(['plate' => 'DEM1A11', 'brand' => 'Fiat', 'model' => 'Strada', 'year' => 2023, 'type' => 'pickup', 'fuel_type' => 'flex', 'tank_capacity' => 55], 41250, $owner),
            app(VehicleService::class)->register(['plate' => 'DEM2B22', 'brand' => 'Renault', 'model' => 'Kangoo', 'year' => 2022, 'type' => 'van', 'fuel_type' => 'flex', 'tank_capacity' => 60], 68300, $owner),
            app(VehicleService::class)->register(['plate' => 'DEM3C33', 'brand' => 'Volkswagen', 'model' => 'Delivery 9.170', 'year' => 2021, 'type' => 'truck', 'fuel_type' => 'diesel', 'tank_capacity' => 150], 152800, $owner),
        ];
        $station = FuelStation::create(['name' => 'Posto Avenida', 'city' => 'São Paulo']);
        $workshop = Workshop::create(['name' => 'Oficina Central', 'city' => 'São Paulo', 'phone' => '1133334444']);

        $people->saveModules($person('frota.demo@exemplo.test'), ['frota' => ['role' => 'manager']]);
        $people->saveModules($person('motorista.demo@exemplo.test'), ['frota' => [
            'role' => 'driver', 'vehicles' => [$vehicles[0]->getKey(), $vehicles[1]->getKey()],
            'license_number' => '01234567890', 'license_category' => 'B', 'license_expires_on' => $start->addYears(3)->toDateString(),
        ]]);
        $driverId = Driver::query()->where('user_id', $users['motorista.demo@exemplo.test']->getKey())->value('id');

        // Fuel-ups every week (full tank), the odometer going up.
        $fuel = [
            [0, 'Strada', 420, 38.5, 22300], [1, 'Kangoo', 380, 45.2, 26100], [2, 'Delivery', 610, 98.0, 61700],
        ];
        foreach ([28, 21, 14, 7, 2] as $round => $daysAgo) {
            foreach ($fuel as [$index, , $km, $liters, $cents]) {
                $vehicle = $vehicles[$index];
                $this->at($this->today->subDays($daysAgo)->setTime(7 + $index, 15 + $round));
                app(FuelService::class)->register([
                    'vehicle' => $vehicle, 'station' => $station, 'driver_id' => $index < 2 ? $driverId : null,
                    'odometer' => $vehicle->fresh()->odometer + $km + $round * 7, 'volume' => $liters + $round, 'total_cents' => $cents + $round * 450,
                    'full_tank' => true, 'fueled_at' => now(),
                ], $owner);
            }
        }

        // A preventive maintenance of the pickup (oil change), 10 days ago.
        $this->at($this->today->subDays(10)->setTime(15, 0));
        app(MaintenanceService::class)->recordMaintenance($vehicles[0], [
            'type' => 'preventive', 'services' => ['oil_change', 'filters'], 'performed_on' => now()->toDateString(),
            'odometer' => $vehicles[0]->fresh()->odometer + 50, 'workshop_id' => $workshop->getKey(),
            'parts_cents' => 28900, 'labor_cents' => 12000, 'description' => 'Troca de óleo e filtros',
        ], $owner);

        // --- Ponto: rules, a workplace, the office schedule, HR, the driver and the employee clock in.
        $this->at($start->setTime(9, 30));
        TimeClockSetting::current()->forceFill(['configured_at' => now()])->save();
        $garage = Workplace::create([
            'name' => 'Garagem Central', 'address' => 'Avenida Paulista, 1000', 'city' => 'São Paulo', 'state' => 'SP',
            'timezone' => self::TZ, 'latitude' => -23.5614, 'longitude' => -46.6559, 'radius_meters' => 300,
        ]);
        $schedule = Schedule::create(['name' => 'Comercial (8h às 18h)'] + ScheduleService::presets()['office']);

        $people->saveModules($person('rh.demo@exemplo.test'), ['ponto' => ['role' => 'manager']]);
        foreach (['motorista.demo@exemplo.test' => ['529.982.247-25', 'Motorista', '0001'], 'funcionario.demo@exemplo.test' => ['111.444.777-35', 'Auxiliar administrativo', '0002']] as $email => [$cpf, $job, $registration]) {
            $record = $person($email);
            $record->setDocument($cpf);
            $record->save();
            $people->saveModules($record, ['ponto' => [
                'clocks_in' => true, 'admitted_on' => $this->today->startOfMonth()->subMonth()->toDateString(), 'job_title' => $job, 'registration' => $registration,
                'workplace_id' => $garage->getKey(), 'schedule_id' => $schedule->getKey(),
            ]]);
            app(PhotoNoticeService::class)->record(Employee::query()->where('person_id', $record->getKey())->sole(), 'phone', $users[$email]);
        }

        $this->punches($users, $garage);
    }

    /**
     * Every working day since the hiring (the 1st of the previous month), today's start of the driver (the
     * employee forgot the last punch two working days ago), then the previous month closed: the driver signed the
     * time sheet, the employee still has to.
     *
     * @param  array<string, User>  $users
     */
    protected function punches(array $users, Workplace $garage): void
    {
        $today = $this->today;
        $holidays = app(HolidayCalendar::class);
        $days = [];

        for ($day = $today->startOfMonth()->subMonth(); $day->lt($today); $day = $day->addDay()) {
            if ($day->isWeekday() && $holidays->holidayOn($day) === null) {
                $days[] = $day;
            }
        }

        foreach ($days as $position => $day) {
            foreach (['motorista.demo@exemplo.test' => 0, 'funcionario.demo@exemplo.test' => 3] as $email => $offset) {
                $times = ['08:0'.(($position + $offset) % 6), '12:0'.(($position * 2 + $offset) % 7), '13:0'.(($position + $offset * 2) % 5), '18:'.str_pad((string) (($position * 3 + $offset) % 12), 2, '0', STR_PAD_LEFT)];

                // Forgot to punch out two working days ago: it shows in "Attention" and in the time sheet.
                if ($email === 'funcionario.demo@exemplo.test' && $position === count($days) - 2) {
                    array_pop($times);
                }

                foreach ($times as $time) {
                    $this->punch($users[$email], $day, $time, $garage);
                }
            }
        }

        // Today the driver already started (if the day already started).
        if ($this->realNow()->format('H:i') > '08:05' && $today->isWeekday()) {
            $this->punch($users['motorista.demo@exemplo.test'], $today, '08:03', $garage);
        }

        $this->closePreviousMonth($users);
    }

    /**
     * @param  array<string, User>  $users
     */
    protected function closePreviousMonth(array $users): void
    {
        $this->realNow();
        $month = $this->today->startOfMonth()->subMonth();
        $closing = app(MonthClosing::class);

        if (! $closing->canClose($month, self::TZ)) {
            return;
        }

        $closing->closeAll($month, $users['dono.demo@exemplo.test']);
        $driver = Employee::query()->whereHas('person', fn ($query) => $query->where('user_id', $users['motorista.demo@exemplo.test']->getKey()))->sole();
        $signed = $closing->current($driver, $month);

        if ($signed !== null) {
            $closing->sign($signed, $users['motorista.demo@exemplo.test'], '127.0.0.1', 'Dados de demonstração');
        }
    }

    protected function punch(User $user, CarbonImmutable $day, string $time, Workplace $garage): void
    {
        $employee = Employee::query()->whereHas('person', fn ($query) => $query->where('user_id', $user->getKey()))->sole();
        $this->at(CarbonImmutable::parse($day->toDateString().' '.$time, self::TZ));

        app(PunchService::class)->punch($employee, [
            'latitude' => $garage->latitude + 0.0002, 'longitude' => $garage->longitude - 0.0001, 'accuracy' => 12,
            'photo_path' => app(PunchPhotoStore::class)->fromDataUrl($this->photo($user->name)),
            'device' => 'Dados de demonstração',
        ], $user);
    }

    /** A placeholder "selfie": the person's initials on the brand colour (no real faces in the demo). */
    protected function photo(string $name): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $image = imagecreatetruecolor(320, 320);
        imagefill($image, 0, 0, imagecolorallocate($image, 4, 66, 153));
        $initials = collect(explode(' ', $name))->map(fn (string $part): string => mb_substr($part, 0, 1))->take(2)->implode('');
        imagestring($image, 5, 145, 150, $initials, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagejpeg($image, null, 80);
        imagedestroy($image);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    protected function realNow(): CarbonImmutable
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        return CarbonImmutable::now(self::TZ);
    }

    /** The moment the demo data happens (records keep their real date). */
    protected function at(CarbonImmutable $moment): void
    {
        CarbonImmutable::setTestNow($moment);
        Carbon::setTestNow($moment);
    }
}
