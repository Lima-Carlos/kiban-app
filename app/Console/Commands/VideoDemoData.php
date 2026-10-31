<?php

namespace App\Console\Commands;

use App\Access\AccessManager;
use App\Connections\ConnectionCatalog;
use App\Models\CompanyProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\Field\Services\OrderExecutionService;
use App\Modules\Field\Services\QuoteService;
use App\Modules\Field\Services\WorkOrderService;
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
use App\People\Epi\EpiDelivery;
use App\People\Epi\EpiService;
use App\People\PeopleService;
use App\People\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    protected const READY = 'Empresa dos vídeos pronta: Rede Exemplo, com Frota, Ponto e Campo e dados de exemplo.';

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
        // Read now: the data below is made at past moments, when this license would not be valid yet.
        $withField = $tenant->license()->hasModule(FieldModule::KEY);

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

        if ($withField) {
            $this->field($users);
        }

        $this->epi($users, $person);

        // The window "your systems now work together" (also the new ones, e.g. PPE + Campo) never shows up in the recordings.
        app(ConnectionCatalog::class)->dismissAllFor($tenant->users()->get());
    }

    /**
     * PPE and tools (People): the catalog with CA numbers and stock, the field team's kits signed with the finger
     * a month ago, gloves already replaced, and one delivery of today still waiting for the signature.
     *
     * @param  array<string, User>  $users  by e-mail
     * @param  \Closure(string): Person  $person
     */
    protected function epi(array $users, \Closure $person): void
    {
        $service = app(EpiService::class);
        $hr = $users['rh.video@exemplo.test'];
        $this->at($this->today->subDays(40)->setTime(8, 30));

        $items = $service->items()->keyBy('key');
        $details = [
            'helmet' => ['ca_number' => '31469', 'ca_expires_on' => $this->today->addYears(2)->toDateString(), 'unit_cost_cents' => 4590, 'stock' => 12, 'min_stock' => 3],
            'glasses' => ['ca_number' => '10346', 'ca_expires_on' => $this->today->addYear()->toDateString(), 'unit_cost_cents' => 1290, 'stock' => 20, 'min_stock' => 5],
            'insulating_gloves' => ['ca_number' => '28420', 'ca_expires_on' => $this->today->addDays(20)->toDateString(), 'unit_cost_cents' => 18900, 'stock' => 4, 'min_stock' => 2],
            'leather_gloves' => ['ca_number' => '15532', 'ca_expires_on' => $this->today->addYears(3)->toDateString(), 'unit_cost_cents' => 1890, 'stock' => 30, 'min_stock' => 10],
            'harness' => ['ca_number' => '35529', 'ca_expires_on' => $this->today->addYears(2)->toDateString(), 'unit_cost_cents' => 32900, 'stock' => 3, 'min_stock' => 1],
            'safety_boots' => ['ca_number' => '40877', 'ca_expires_on' => $this->today->addYears(2)->toDateString(), 'unit_cost_cents' => 12900, 'size' => '41', 'stock' => 6, 'min_stock' => 2],
            'ear_protection' => ['ca_number' => '5745', 'ca_expires_on' => $this->today->addYears(4)->toDateString(), 'unit_cost_cents' => 290, 'stock' => 40, 'min_stock' => 10],
            'multimeter' => ['unit_cost_cents' => 21900, 'stock' => 2, 'min_stock' => 1, 'code' => 'FER-012'],
            'ladder' => ['unit_cost_cents' => 54900, 'stock' => 3, 'min_stock' => 1, 'code' => 'FER-020'],
            'pliers' => ['unit_cost_cents' => 6900, 'stock' => 8, 'min_stock' => 2, 'code' => 'FER-001'],
        ];

        foreach ($details as $key => $values) {
            $items[$key]->update($values);
        }

        $kit = fn (): array => collect(['helmet', 'glasses', 'leather_gloves', 'harness', 'safety_boots', 'multimeter', 'pliers'])
            ->map(fn (string $key): array => ['item_id' => $items[$key]->getKey(), 'quantity' => $key === 'leather_gloves' ? 2 : 1])->all();
        $sign = function (EpiDelivery $delivery, bool $own) use ($service, $hr): void {
            $user = $own ? $delivery->person->user : $hr;
            $service->sign($delivery, $this->signatureImage($delivery->person->name), $delivery->person->name, $user, $own, '192.0.2.10',
                'Mozilla/5.0 (Linux; Android 14; moto g54) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36');
        };

        foreach (['tecnico.video@exemplo.test', 'tecnica.video@exemplo.test'] as $index => $email) {
            if (! isset($users[$email])) {
                continue;
            }

            // The kit on the first day, signed on HR's phone.
            $this->at($this->today->subDays(38 - $index)->setTime(8, 15));
            $first = $service->deliver($person($email), $kit(), ['delivered_on' => now()->toDateString(), 'reason' => 'first'], $hr);
            $this->at(CarbonImmutable::now()->addMinutes(3));
            $sign($first, false);

            // The gloves replaced after a month, signed later on the technician's own phone.
            $this->at($this->today->subDays(6 + $index)->setTime(7, 50));
            $gloves = $first->lines()->where('item_id', $items['leather_gloves']->getKey())->sole();
            $exchange = $service->exchange($gloves, ['reason' => 'wear', 'outcome' => 'discard', 'delivered_on' => now()->toDateString()], $hr);
            $this->at(CarbonImmutable::now()->addHours(2));
            $sign($exchange, true);
        }

        // Today: hearing protection for Pedro (no login), waiting for the signature on HR's phone.
        $this->at($this->today->setTime(7, 40));
        $pedro = Person::query()->where('name', 'Pedro Rocha')->first();

        if ($pedro !== null) {
            $service->deliver($pedro, [['item_id' => $items['ear_protection']->getKey(), 'quantity' => 2]], ['delivered_on' => now()->toDateString(), 'reason' => 'first'], $hr);
        }
    }

    /** A handwritten-looking signature (a few waves, transparent background), drawn with GD. */
    protected function signatureImage(string $name): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return 'data:image/png;base64,';
        }

        $image = imagecreatetruecolor(600, 180);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        $ink = imagecolorallocate($image, 17, 24, 39);
        imagesetthickness($image, 4);
        $seed = crc32($name);
        $previous = null;

        for ($x = 40; $x <= 560; $x += 4) {
            $y = (int) (95 + 38 * sin(($x + $seed % 50) / 23) * cos($x / (61 + $seed % 17)) - ($x > 300 ? 10 : 0));

            if ($previous !== null) {
                imageline($image, $previous[0], $previous[1], $x, $y, $ink);
            }

            $previous = [$x, $y];
        }

        imageline($image, 60, 150, 520, 140, $ink);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /** Clearly invented customers around the base (no real business), each with a point on the map. */
    protected const FIELD_CUSTOMERS = [
        ['Padaria Pão Quente Ltda', 'Pão Quente', 'company', 'Rua da Bahia', '1200', 'Centro', 0.0030, -0.0021, '31988881001'],
        ['Maria Aparecida Souza', null, 'person', 'Rua Espírito Santo', '455', 'Centro', -0.0042, 0.0018, '31988881002'],
        ['Clínica Bem-Estar Ltda', 'Clínica Bem-Estar', 'company', 'Avenida Augusto de Lima', '890', 'Barro Preto', 0.0061, 0.0049, '31988881003'],
        ['João Batista Ferreira', null, 'person', 'Rua Tupinambás', '310', 'Centro', -0.0018, -0.0057, '31988881004'],
        ['Mercadinho Bom Preço Ltda', 'Mercadinho Bom Preço', 'company', 'Rua Curitiba', '1450', 'Centro', 0.0072, -0.0035, '31988881005'],
        ['Escritório Silva & Costa', null, 'company', 'Avenida Álvares Cabral', '700', 'Lourdes', -0.0065, 0.0071, '31988881006'],
        ['Ana Paula Ribeiro', null, 'person', 'Rua dos Guajajaras', '1020', 'Centro', 0.0025, 0.0062, '31988881007'],
        ['Roberto Alves Lima', null, 'person', 'Rua Guaicurus', '220', 'Centro', -0.0081, -0.0024, '31988881008'],
    ];

    /**
     * Kiban Campo: the ISP model with prices, the company identity, the technicians, customers, today's agenda,
     * orders to schedule, orders done yesterday (with checklist, photo and signature) and quotes.
     *
     * @param  array<string, User>  $users  by e-mail
     */
    protected function field(array $users): void
    {
        $owner = $users['dona.video@exemplo.test'];
        $this->at($this->today->subDays(10)->setTime(9, 0));

        FieldSetting::current()->update(['business' => 'isp', 'currency' => 'BRL', 'configured_at' => now()]);
        app(FieldTemplates::class)->apply('isp');
        $prices = ['Instalação' => 15000, 'Sem sinal / lentidão' => 8000, 'Mudança de endereço' => 12000, 'Retirada de equipamento' => 0, 'Visita técnica' => 9000];
        foreach ($prices as $name => $cents) {
            ServiceType::query()->where('name', $name)->update(['price_cents' => $cents]);
        }
        foreach (['Cabo drop' => 120, 'Conector de fibra' => 800, 'ONU' => 18990, 'Roteador Wi-Fi' => 18990, 'Esticador / ancoragem' => 350, 'Cordão óptico' => 1500] as $name => $cents) {
            Material::query()->where('name', $name)->update(['price_cents' => $cents]);
        }

        CompanyProfile::query()->create([
            'legal_name' => 'Rede Exemplo Fibra Ltda', 'color' => '#0F766E', 'document' => '11.444.777/0001-61', 'phone' => '(31) 3333-0000',
            'email' => 'contato@exemplo.test', 'address' => 'Av. Afonso Pena, 1500 - Centro - Belo Horizonte/MG',
            'report_footer' => 'Garantia de 90 dias para o serviço. Atendimento de segunda a sábado, das 8h às 18h.',
        ]);

        foreach (['tecnico.video@exemplo.test', 'tecnica.video@exemplo.test', 'empleado.video@exemplo.test', 'employee.video@exemplo.test'] as $email) {
            app(AccessManager::class)->set($users[$email], FieldModule::KEY, FieldRole::Technician->value);
        }

        $customers = [];
        foreach (self::FIELD_CUSTOMERS as $index => [$name, $trade, $kind, $street, $number, $district, $dLat, $dLng, $phone]) {
            $customer = Customer::create(['name' => $name, 'trade_name' => $trade, 'kind' => $kind, 'phone' => '55'.$phone, 'external_code' => (string) (1001 + $index)]);
            $customer->sites()->create([
                'label' => $kind === 'company' ? 'Matriz' : 'Casa', 'street' => $street, 'number' => $number, 'district' => $district,
                'city' => 'Belo Horizonte', 'state' => 'MG', 'country' => 'BR', 'is_main' => true,
                'latitude' => round(self::BASE['latitude'] + $dLat, 6), 'longitude' => round(self::BASE['longitude'] + $dLng, 6),
            ]);
            $customers[] = $customer;
        }

        $type = fn (string $name): int => (int) ServiceType::query()->where('name', $name)->value('id');
        $orders = app(WorkOrderService::class);
        $lucas = $users['tecnico.video@exemplo.test'];
        $ana = $users['tecnica.video@exemplo.test'];

        // Yesterday: three services done on the phone (checklist, photo, material, signature).
        $yesterday = $this->today->subDay();
        foreach ([[0, 'Instalação', $lucas, '08:30', 'Quer internet de 500 mega'], [2, 'Sem sinal / lentidão', $ana, '10:00', 'Sem internet desde a noite anterior'], [4, 'Instalação', $lucas, '14:00', 'Internet para o caixa e o Wi-Fi dos clientes']] as [$who, $name, $technician, $time, $reported]) {
            $this->at($yesterday->setTime(7, 30));
            $order = $orders->open(['customer_id' => $customers[$who]->getKey(), 'service_type_id' => $type($name), 'reported' => $reported, 'technician_id' => $technician->getKey(), 'scheduled_at' => $yesterday->setTimeFromTimeString($time)->utc()->toDateTimeString()], $owner);
            $this->doneOnThePhone($order, $technician, $yesterday->setTimeFromTimeString($time));
        }

        // Today: the agenda of Lucas and Ana, and two orders still to schedule (one urgent).
        $this->at($this->today->setTime(7, 40));
        foreach ([[1, 'Instalação', $lucas, '08:30'], [3, 'Sem sinal / lentidão', $lucas, '10:30'], [6, 'Mudança de endereço', $lucas, '14:00'], [5, 'Visita técnica', $ana, '09:00'], [7, 'Sem sinal / lentidão', $ana, '13:30']] as [$who, $name, $technician, $time]) {
            $orders->open(['customer_id' => $customers[$who]->getKey(), 'service_type_id' => $type($name), 'technician_id' => $technician->getKey(), 'scheduled_at' => $this->today->setTimeFromTimeString($time)->utc()->toDateTimeString()], $owner);
        }
        $orders->open(['customer_id' => $customers[2]->getKey(), 'service_type_id' => $type('Visita técnica'), 'priority' => 'urgent', 'reported' => 'Câmera do caixa sem imagem'], $owner);
        $orders->open(['customer_id' => $customers[0]->getKey(), 'service_type_id' => $type('Mudança de endereço'), 'reported' => 'Vai abrir uma filial'], $owner);

        // Quotes: one approved (became an order), one sent waiting for the customer, one being written.
        $quotes = app(QuoteService::class);
        $line = fn (string $name, float $quantity = 1): array => ($service = ServiceType::query()->where('name', $name)->first())
            ? ['service_type_id' => $service->getKey(), 'description' => $service->name, 'quantity' => $quantity, 'unit' => 'un', 'unit_price_cents' => (int) $service->price_cents]
            : (fn (Material $material): array => ['material_id' => $material->getKey(), 'description' => $material->name, 'quantity' => $quantity, 'unit' => $material->unit, 'unit_price_cents' => (int) $material->price_cents])(Material::query()->where('name', $name)->sole());

        $this->at($this->today->subDays(2)->setTime(11, 0));
        $approved = $quotes->send($quotes->create(['customer_id' => $customers[6]->getKey(), 'notes' => "Instalação em até 3 dias úteis.\nPagamento na instalação (Pix ou cartão)."], [$line('Mudança de endereço'), $line('Cabo drop', 40)], $owner));
        $this->at($this->today->subDays(2)->setTime(15, 20));
        $quotes->approve($approved, 'Ana Paula Ribeiro', ['ip' => '177.10.20.30', 'device' => 'Celular']);

        $this->at($this->today->subDay()->setTime(16, 0));
        $quotes->send($quotes->create(['customer_id' => $customers[5]->getKey(), 'notes' => 'Rede para 12 computadores e Wi-Fi em todo o escritório.'], [$line('Instalação'), $line('Roteador Wi-Fi', 2), $line('Cabo drop', 60)], $owner));

        $this->at($this->today->setTime(7, 50));
        $quotes->create(['customer_id' => $customers[3]->getKey()], [$line('Instalação'), $line('ONU')], $owner);
    }

    /** One service done on the technician's phone, piece by piece, as the app sends it. */
    protected function doneOnThePhone(WorkOrder $order, User $technician, CarbonImmutable $start): void
    {
        $send = function (string $type, array $data, CarbonImmutable $at) use ($order, $technician): void {
            $this->at($at);
            app(OrderExecutionService::class)->apply($order->fresh(), $technician, ['id' => (string) Str::uuid(), 'type' => $type, 'taken_at' => $at->getTimestampMs(), 'data' => $data]);
        };

        $site = $order->site;
        $send('check_in', ['latitude' => $site->latitude, 'longitude' => $site->longitude, 'accuracy' => 8], $start->addMinutes(5));

        foreach ((array) $order->checklist as $index => $item) {
            $at = $start->addMinutes(10 + $index * 4);
            match ($item['type']) {
                'photo' => $send('photo', ['key' => $item['key'], 'image' => $this->photo('CTO')], $at),
                'number' => $send('answer', ['key' => $item['key'], 'value' => $item['unit'] === 'dBm' ? '-19.4' : '512'], $at),
                'text' => $send('answer', ['key' => $item['key'], 'value' => $item['key'] === 'onu_serial' ? 'ZTEG'.random_int(10000000, 99999999) : 'Conector oxidado na caixa; trocado e testado.'], $at),
                'choice' => $send('answer', ['key' => $item['key'], 'value' => $item['options'][1] ?? ($item['options'][0] ?? '')], $at),
                'yes_no' => $send('answer', ['key' => $item['key'], 'value' => 'yes'], $at),
                default => $send('answer', ['key' => $item['key'], 'value' => '1'], $at),
            };
        }

        $send('material', ['material_id' => Material::query()->where('name', 'Cabo drop')->value('id'), 'quantity' => 35], $start->addMinutes(50));
        $send('note', ['text' => 'Serviço feito e testado com o cliente.'], $start->addMinutes(52));
        $send('signature', ['name' => $order->customer->name, 'image' => $this->photo('OK')], $start->addMinutes(55));
        $send('finish', [], $start->addMinutes(57));
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
