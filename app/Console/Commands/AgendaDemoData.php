<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Agenda\Enums\Species;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\Item;
use App\Modules\Agenda\Models\Package;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Models\Service;
use App\Modules\Agenda\Services\AgendaException;
use App\Modules\Agenda\Services\AgendaPeople;
use App\Modules\Agenda\Services\AgendaTemplates;
use App\Modules\Agenda\Services\AppointmentBook;
use App\Modules\Agenda\Services\PackageService;
use App\Modules\Agenda\Services\SlotFinder;
use App\Modules\Agenda\Services\WorkingHours;
use App\People\Person;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sample data of a pet shop for screenshots and tests by hand: the pet shop model of services, two professionals,
 * tutors with pets (invented names), appointments today and in the next days in every status, a package sold and
 * a client who misses a lot. Only for a company that has the Agenda; it only adds (run it on a test company).
 */
class AgendaDemoData extends Command
{
    protected $signature = 'kiban:agenda-demo {tenant : address of the company (e.g. pet-feliz-teste)}';

    protected $description = 'Fill a company that has the Agenda with sample pet shop data (tutors, pets, appointments)';

    /** Invented tutors and pets (never real people). */
    protected const CLIENTS = [
        ['Marina Exemplo', '11990000001', [['Thor', 'Shih-tzu', 'small'], ['Luna', 'Gato SRD', 'small', 'cat']]],
        ['Paulo Fictício', '11990000002', [['Bidu', 'Golden Retriever', 'large']]],
        ['Ana Teste', '11990000003', [['Pipoca', 'Poodle', 'small']]],
        ['Rafael Modelo', '11990000004', [['Rex', 'Pastor Alemão', 'xlarge']]],
        ['Beatriz Demo', '11990000005', [['Mel', 'Lhasa Apso', 'medium']]],
        ['Carlos Inventado', '11990000006', [['Fred', 'Beagle', 'medium']]],
    ];

    public function handle(TenantContext $context): int
    {
        $tenant = Tenant::query()->where('subdomain', $this->argument('tenant'))->first();

        if ($tenant === null || ! $tenant->license()->hasModule(AgendaModule::KEY)) {
            $this->components->error('Empresa não encontrada ou sem a Agenda.');

            return self::FAILURE;
        }

        $context->run($tenant, fn () => $this->fill());
        $this->components->info('Dados de exemplo da Agenda prontos em '.$tenant->subdomain.'.');

        return self::SUCCESS;
    }

    protected function fill(): void
    {
        $settings = AgendaSetting::current();
        $settings->update(['business' => 'petshop', 'pickup_enabled' => true, 'configured_at' => $settings->configured_at ?? now()]);
        app(AgendaTemplates::class)->apply('petshop');

        $professionals = collect(['Júlia Banhista', 'Diego Tosador'])->map(function (string $name, int $index): Professional {
            $person = Person::query()->firstOrCreate(['name' => $name]);
            app(AgendaPeople::class)->save($person, ['attends' => true]);
            $professional = Professional::query()->where('person_id', $person->getKey())->sole();
            $professional->update(['commission_bp' => $index === 0 ? 3000 : 4000, 'color' => Professional::COLORS[$index + 1]]);

            return $professional;
        });

        $items = collect(self::CLIENTS)->flatMap(function (array $row): array {
            [$name, $phone, $pets] = $row;
            $client = Client::query()->where('phone', '55'.$phone)->first();

            if ($client === null) {
                $client = new Client;
                $client->forceFill(['name' => $name, 'phone' => '55'.$phone, 'kind' => Client::PERSON])->save();
            }

            return collect($pets)->map(fn (array $pet): Item => Item::query()->firstOrCreate(
                ['customer_id' => $client->getKey(), 'name' => $pet[0]],
                ['breed' => $pet[1], 'size' => ItemSize::from($pet[2]), 'species' => Species::from($pet[3] ?? 'dog')],
            ))->all();
        })->values();

        $services = Service::query()->get()->keyBy('template_key');
        $book = app(AppointmentBook::class);
        $today = CarbonImmutable::now(AgendaSetting::timezone())->startOfDay();

        // A package of baths for the first tutor.
        if (($package = Package::query()->first()) !== null && ! $items[0]->client->packages()->exists()) {
            app(PackageService::class)->sell($items[0]->client, $package, $items[0], null);
        }

        $plan = [
            // [day offset, time, item, professional, services, status]
            [0, '08:00', 0, 0, ['bath', 'nails'], AppointmentStatus::Delivered],
            [0, '09:00', 2, 1, ['hygienic_grooming'], AppointmentStatus::Ready],
            [0, '10:00', 3, 0, ['bath', 'hydration'], AppointmentStatus::InService],
            [0, '11:00', 4, 1, ['bath_grooming'], AppointmentStatus::Arrived],
            [0, '14:00', 5, 0, ['bath', 'pickup'], AppointmentStatus::Confirmed],
            [0, '15:00', 1, 1, ['bath'], AppointmentStatus::Scheduled],
            [1, '09:00', 6, 0, ['bath'], AppointmentStatus::Scheduled],
            [1, '10:30', 0, 1, ['bath_grooming'], AppointmentStatus::Scheduled],
            [1, '14:00', 2, 0, ['bath', 'teeth'], AppointmentStatus::Confirmed],
            [2, '09:00', 3, 1, ['bath_grooming', 'pickup'], AppointmentStatus::Scheduled],
            [-1, '09:00', 5, 0, ['bath'], AppointmentStatus::NoShow],
            [-8, '09:00', 5, 0, ['bath'], AppointmentStatus::NoShow],
        ];

        $days = $this->workingDays($plan, $today, $professionals->all());
        $slots = app(SlotFinder::class);

        foreach ($plan as [$offset, $time, $itemIndex, $who, $keys, $status]) {
            $item = $items[$itemIndex % $items->count()];
            $day = $days[$offset];

            // Already there (the command was run again today).
            if (Appointment::query()->where('item_id', $item->getKey())->where('professional_id', $professionals[$who]->getKey())
                ->whereBetween('starts_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()])->exists()) {
                continue;
            }

            // The free time of the professional closest to the planned one (inside the hours, no clash).
            $minutes = $slots->minutes(collect($keys)->map(fn (string $key): ?Service => $services->get($key))->filter(), $item->size);
            $planned = $day->setTimeFromTimeString($time);
            $start = collect($slots->slots($professionals[$who], $day, $minutes))
                ->sortBy(fn (CarbonImmutable $free): int => (int) abs($free->diffInMinutes($planned)))
                ->first() ?? $planned;

            // Moved to a later day (today is a day off): nothing has happened yet.
            if ($day->gt($today) && $status->position() > AppointmentStatus::Confirmed->position()) {
                $status = AppointmentStatus::Confirmed;
            }

            try {
                $appointment = $book->book([
                    'customer_id' => $item->customer_id,
                    'item_id' => $item->getKey(),
                    'professional_id' => $professionals[$who]->getKey(),
                    'starts_at' => $start,
                    'service_ids' => collect($keys)->map(fn (string $key): ?int => $services->get($key)?->getKey())->filter()->all(),
                    'pickup_address' => in_array('pickup', $keys, true) ? 'Rua das Acácias, 120' : null,
                    'pickup_from' => in_array('pickup', $keys, true) ? '07:30' : null,
                    'pickup_until' => in_array('pickup', $keys, true) ? '08:30' : null,
                ], null, $offset === 1 && $time === '09:00' ? Appointment::ONLINE : Appointment::DESK);

                $this->walk($appointment, $status, $start);
            } catch (AgendaException $exception) {
                $this->components->warn($exception->getMessage());
            }
        }
    }

    /**
     * The real date of each day offset of the plan, so the samples fall on working days: "today" is today or, on a
     * day off (e.g. Sunday), the next day all of its professionals work; the next offsets are the following such
     * days; past offsets look back the same way.
     *
     * @param  array<int, array<int, mixed>>  $plan
     * @param  array<int, Professional>  $professionals
     * @return array<int, CarbonImmutable> by day offset
     */
    protected function workingDays(array $plan, CarbonImmutable $today, array $professionals): array
    {
        $hours = app(WorkingHours::class);
        $byOffset = collect($plan)->groupBy(0);
        $works = fn (int $offset, CarbonImmutable $day): bool => $byOffset->get($offset)->pluck(3)->unique()
            ->every(fn (int $who): bool => $hours->intervals($professionals[$who], $day) !== []);

        $days = [];
        $next = $today;

        foreach ($byOffset->keys()->filter(fn (int $offset): bool => $offset >= 0)->sort() as $offset) {
            $day = $next;

            for ($tries = 0; $tries < 14 && ! $works($offset, $day); $tries++) {
                $day = $day->addDay();
            }

            $days[$offset] = $day;
            $next = $day->addDay();
        }

        foreach ($byOffset->keys()->filter(fn (int $offset): bool => $offset < 0) as $offset) {
            $day = $today->addDays($offset);

            for ($tries = 0; $tries < 14 && ! $works($offset, $day); $tries++) {
                $day = $day->subDay();
            }

            $days[$offset] = $day;
        }

        return $days;
    }

    /** Moves the appointment along to the wanted status (marking times as if it really happened). */
    protected function walk(Appointment $appointment, AppointmentStatus $status, CarbonImmutable $start): void
    {
        if ($status === AppointmentStatus::NoShow) {
            $appointment->forceFill(['status' => $status, 'no_show_at' => $start->addHour()])->save();

            return;
        }

        $columns = ['confirmed' => 'confirmed_at', 'arrived' => 'arrived_at', 'in_service' => 'started_at', 'ready' => 'ready_at', 'delivered' => 'delivered_at'];
        $fill = ['status' => $status];

        foreach ($columns as $value => $column) {
            if (AppointmentStatus::from($value)->position() <= $status->position()) {
                $fill[$column] = $start;
            }
        }

        $appointment->forceFill($fill)->save();
    }
}
