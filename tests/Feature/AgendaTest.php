<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Connections\Bridges\AgendaField;
use App\Connections\Bridges\AgendaTimeClock;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Agenda\Filament\AgendaSettingsPage;
use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
use App\Modules\Agenda\Filament\Appointments\Pages\CreateAppointment;
use App\Modules\Agenda\Filament\Appointments\Pages\ListAppointments;
use App\Modules\Agenda\Filament\Appointments\Pages\ViewAppointment;
use App\Modules\Agenda\Filament\CalendarPage;
use App\Modules\Agenda\Filament\Clients\ClientResource;
use App\Modules\Agenda\Filament\Clients\Pages\CreateClient;
use App\Modules\Agenda\Filament\CommissionsPage;
use App\Modules\Agenda\Filament\Professionals\Pages\ManageProfessionals;
use App\Modules\Agenda\Filament\Professionals\ProfessionalResource;
use App\Modules\Agenda\Filament\RemindersPage;
use App\Modules\Agenda\Filament\Services\ServiceResource;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\Item;
use App\Modules\Agenda\Models\Package;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Models\Service;
use App\Modules\Agenda\Models\TimeOff;
use App\Modules\Agenda\Services\AgendaException;
use App\Modules\Agenda\Services\AgendaMessages;
use App\Modules\Agenda\Services\AgendaPeople;
use App\Modules\Agenda\Services\AgendaReports;
use App\Modules\Agenda\Services\AgendaTemplates;
use App\Modules\Agenda\Services\AppointmentBook;
use App\Modules\Agenda\Services\PackageService;
use App\Modules\Agenda\Services\SlotFinder;
use App\Modules\Agenda\Services\WorkingHours;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\EmployeeSchedule;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\Workplace;
use App\People\Person;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core Agenda (pet shops): free times from the hours, breaks, time off and other appointments; the public
 * booking page and the client's link; the way of an appointment and no-shows; packages; commissions; the plan
 * limit; permissions; the Campo and Ponto bridges; and one company never seeing another one's agenda.
 */
class AgendaTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected Professional $ana;

    protected Service $bath;

    protected Client $maria;

    protected Item $thor;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 03/11/2026, 08:00 in São Paulo.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 11:00:00', 'UTC'));
        $this->company = $this->tenant('petfeliz', ['agenda' => ['limits' => ['professionals' => 3]]]);
        $this->owner = $this->signIn($this->company);

        AgendaSetting::current()->update(['business' => 'petshop', 'configured_at' => now(), 'slot_minutes' => 30, 'min_notice_minutes' => 60, 'cancel_hours' => 4]);
        app(AgendaTemplates::class)->apply('petshop');
        $this->bath = Service::query()->where('template_key', 'bath')->sole();
        $this->ana = $this->professional('Ana Banhista');

        $this->maria = new Client;
        $this->maria->forceFill(['name' => 'Maria Tutora', 'phone' => '5511988887777', 'kind' => Client::PERSON])->save();
        $this->thor = Item::create(['customer_id' => $this->maria->getKey(), 'name' => 'Thor', 'breed' => 'Shih-tzu', 'size' => ItemSize::Medium]);
    }

    protected function professional(string $name, int $commission = 3000): Professional
    {
        $person = Person::create(['name' => $name]);
        app(AgendaPeople::class)->save($person, ['attends' => true]);
        $professional = Professional::query()->where('person_id', $person->getKey())->sole();
        $professional->update(['commission_bp' => $commission]);

        return $professional;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function book(string $when, array $extra = [], ?Professional $professional = null): Appointment
    {
        return app(AppointmentBook::class)->book($extra + [
            'customer_id' => $this->maria->getKey(),
            'item_id' => $this->thor->getKey(),
            'professional_id' => ($professional ?? $this->ana)->getKey(),
            'starts_at' => $when,
            'service_ids' => [$this->bath->getKey()],
        ], $this->owner);
    }

    /**
     * @return array<int, string>
     */
    protected function times(string $date, int $minutes = 60, ?Professional $professional = null): array
    {
        return array_map(fn (CarbonImmutable $time): string => $time->format('H:i'),
            app(SlotFinder::class)->slots($professional ?? $this->ana, CarbonImmutable::parse($date, 'America/Sao_Paulo'), $minutes));
    }

    public function test_free_times_follow_hours_break_duration_and_other_appointments(): void
    {
        // Mon–Fri 08:00–18:00 with lunch 12:00–13:00, every 30 minutes, 1 hour of bath.
        $times = $this->times('2026-11-04');
        $this->assertSame('08:00', $times[0]);
        $this->assertContains('11:00', $times);
        $this->assertNotContains('11:30', $times); // would run into lunch
        $this->assertNotContains('12:00', $times);
        $this->assertContains('13:00', $times);
        $this->assertSame('17:00', end($times));

        // The size sets the time: a large dog takes 90 minutes (10:30 ends at lunch, 11:00 would not; last one 16:30).
        $this->assertSame(90, $this->bath->durationFor(ItemSize::Large));
        $this->assertContains('10:30', $this->times('2026-11-04', 90));
        $this->assertNotContains('11:00', $this->times('2026-11-04', 90));
        $this->assertSame('16:30', collect($this->times('2026-11-04', 90))->last());

        // An appointment at 09:00 takes 08:30 (overlap) and 09:00, not 10:00.
        $this->book('2026-11-04 09:00');
        $times = $this->times('2026-11-04');
        $this->assertNotContains('08:30', $times);
        $this->assertNotContains('09:30', $times);
        $this->assertContains('08:00', $times);
        $this->assertContains('10:00', $times);

        // Time off in the afternoon; Sunday is a day off.
        TimeOff::create(['professional_id' => $this->ana->getKey(), 'starts_at' => CarbonImmutable::parse('2026-11-04 14:00', 'America/Sao_Paulo')->utc(), 'ends_at' => CarbonImmutable::parse('2026-11-04 16:00', 'America/Sao_Paulo')->utc()]);
        $times = $this->times('2026-11-04');
        $this->assertNotContains('13:30', $times);
        $this->assertNotContains('15:30', $times);
        $this->assertContains('16:00', $times);
        $this->assertSame([], $this->times('2026-11-08'));

        // Canceled appointments give the time back.
        $other = $this->book('2026-11-04 10:00');
        $this->assertNotContains('10:00', $this->times('2026-11-04'));
        app(AppointmentBook::class)->cancel($other, 'Choveu', $this->owner);
        $this->assertContains('10:00', $this->times('2026-11-04'));

        // Price and time per size are kept on the appointment.
        $appointment = $this->book('2026-11-05 08:00');
        $this->assertSame([6500, 60, 3000], [$appointment->price_cents, $appointment->minutes(), $appointment->lines->first()->commission_bp]);
    }

    public function test_the_hours_can_come_from_the_time_clock(): void
    {
        $this->company = $this->tenant('petponto', ['agenda' => [], 'ponto' => []]);
        $this->signIn($this->company);
        AgendaSetting::current()->update(['slot_minutes' => 60]);
        $professional = $this->professional('Bia Tosadora');

        $workplace = Workplace::create(['name' => 'Loja', 'timezone' => 'America/Sao_Paulo']);
        $schedule = Schedule::create(['name' => 'Tarde', 'type' => Schedule::WEEKLY, 'week' => ['3' => ['works' => true, 'start' => '13:00', 'end' => '17:00', 'break_start' => null, 'break_end' => null]]]);
        $employee = new Employee(['admitted_on' => '2026-01-01', 'workplace_id' => $workplace->getKey()]);
        $employee->forceFill(['person_id' => $professional->person_id])->save();
        EmployeeSchedule::create(['employee_id' => $employee->getKey(), 'schedule_id' => $schedule->getKey(), 'starts_on' => '2026-01-01']);

        $this->assertSame(['13:00', '14:00', '15:00', '16:00'], $this->times('2026-11-04', 60, $professional));

        // Turned off: their own week of the Agenda again.
        $professional->update(['follows_time_clock' => false]);
        $this->assertSame('08:00', $this->times('2026-11-04', 60, $professional)[0]);

        // The calendar shows who clocked in (nobody yet) and never punches.
        $this->assertSame('none', app(AgendaTimeClock::class)->today(collect([$professional]))[$professional->getKey()]['state']);
    }

    public function test_desk_books_and_moves_along_the_way_with_the_whatsapp_buttons(): void
    {
        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'customer_id' => $this->maria->getKey(),
                'item_id' => $this->thor->getKey(),
                'service_ids' => [$this->bath->getKey(), Service::query()->where('template_key', 'hydration')->value('id')],
                'professional_id' => $this->ana->getKey(),
                'date' => '2026-11-03',
                'time' => '10:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::query()->sole();
        $this->assertSame(['Banho + Hidratação', 9500, 80, AppointmentStatus::Scheduled], [$appointment->servicesLabel(), $appointment->price_cents, $appointment->minutes(), $appointment->status]);

        Livewire::test(CalendarPage::class)->assertOk()->assertSee('Thor')->assertSee('Ana Banhista')
            ->call('advance', $appointment->getKey())->call('advance', $appointment->getKey())->call('advance', $appointment->getKey());
        $this->assertSame(AppointmentStatus::Ready, $appointment->fresh()->status);

        // "Pet ready" opens WhatsApp with the message for the tutor.
        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertOk()
            ->assertActionVisible('whatsappReady')
            ->call('markSent', 'ready')
            ->callAction('to_delivered');
        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Delivered, $appointment->status);
        $this->assertNotNull($appointment->ready_sent_at);
        $this->assertStringStartsWith('https://wa.me/5511988887777?text=', app(AgendaMessages::class)->ready($appointment));

        // Final: never changes again.
        $this->expectException(AgendaException::class);
        app(AppointmentBook::class)->setStatus($appointment, AppointmentStatus::NoShow, $this->owner);
    }

    public function test_no_shows_are_counted_and_flag_the_client(): void
    {
        $first = $this->book('2026-11-03 10:00');

        // Not before the time.
        $this->assertSame(__('agenda.errors.no_show_early'), rescue(fn () => app(AppointmentBook::class)->setStatus($first, AppointmentStatus::NoShow, $this->owner), fn ($e) => $e->getMessage(), false));

        $second = $this->book('2026-11-03 14:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 20:00:00', 'UTC'));
        app(AppointmentBook::class)->setStatus($first, AppointmentStatus::NoShow, $this->owner);
        $this->assertFalse($this->maria->isFlagged());
        app(AppointmentBook::class)->setStatus($second, AppointmentStatus::NoShow, $this->owner);
        $this->assertSame(2, $this->maria->noShows());
        $this->assertTrue($this->maria->isFlagged());

        // Coming soon again: the desk sees it in "Attention".
        $this->book('2026-11-04 10:00');
        $this->assertStringContainsString('Maria Tutora', collect(app(AgendaReports::class)->attention())->pluck('text')->implode(' '));
    }

    public function test_packages_are_used_by_the_appointments(): void
    {
        $package = Package::query()->sole();
        $sold = app(PackageService::class)->sell($this->maria, $package, $this->thor, $this->owner);
        $this->assertSame([4, '2026-12-18'], [$sold->quantity, $sold->expires_on->toDateString()]);

        // Booking a bath uses it by itself.
        $one = $this->book('2026-11-03 10:00');
        $this->assertSame($sold->getKey(), $one->client_package_id);
        $this->assertSame([1, 0, 3], [$sold->taken(), $sold->used(), $sold->left()]);

        // Canceling gives the session back; done counts as used.
        $two = $this->book('2026-11-04 10:00');
        app(AppointmentBook::class)->cancel($two, null, $this->owner);
        app(AppointmentBook::class)->setStatus($one, AppointmentStatus::Ready, $this->owner);
        $this->assertSame([1, 1, 3], [$sold->taken(), $sold->used(), $sold->left()]);

        // "None" on purpose, and an expired package is not used.
        $this->assertNull($this->book('2026-11-05 10:00', ['client_package_id' => null])->client_package_id);
        $this->assertNull($this->book('2026-12-21 10:00')->client_package_id);

        // Expiring soon shows in "Attention".
        $this->travelTo(CarbonImmutable::parse('2026-12-15 12:00:00', 'UTC'));
        $this->assertStringContainsString('Pacote 4 banhos', collect(app(AgendaReports::class)->attention())->pluck('text')->implode(' '));
    }

    public function test_commissions_of_the_month_and_the_spreadsheet(): void
    {
        $grooming = Service::query()->where('template_key', 'bath_grooming')->sole();
        $bia = $this->professional('Bia Tosadora', 2000);
        $bia->syncServices([['service_id' => $grooming->getKey(), 'commission_bp' => 5000]]);

        $a = $this->book('2026-11-03 10:00');
        $b = $this->book('2026-11-03 13:00', ['service_ids' => [$grooming->getKey()]], $bia);
        $this->book('2026-11-04 10:00'); // not done: no commission
        app(AppointmentBook::class)->setStatus($a, AppointmentStatus::Delivered, $this->owner);
        app(AppointmentBook::class)->setStatus($b, AppointmentStatus::Ready, $this->owner);

        // Bia only does grooming.
        $this->assertSame(__('agenda.errors.professional_does_not', ['name' => 'Bia Tosadora', 'service' => 'Banho']), rescue(fn () => $this->book('2026-11-05 10:00', [], $bia), fn ($e) => $e->getMessage(), false));

        $rows = collect(app(AgendaReports::class)->commissions(CarbonImmutable::parse('2026-11-01')))->keyBy(fn ($row) => $row['professional']->name());
        // Ana: 30% of R$ 65,00 = R$ 19,50. Bia: 50% (own for grooming) of R$ 110,00 = R$ 55,00.
        $this->assertSame([1, 6500, 1950], [$rows['Ana Banhista']['appointments'], $rows['Ana Banhista']['revenue'], $rows['Ana Banhista']['commission']]);
        $this->assertSame([1, 11000, 5500], [$rows['Bia Tosadora']['appointments'], $rows['Bia Tosadora']['revenue'], $rows['Bia Tosadora']['commission']]);

        $file = app(AgendaReports::class)->exportCommissions(CarbonImmutable::parse('2026-11-01'));
        $this->assertFileExists($file);
        unlink($file);

        Livewire::test(CommissionsPage::class)->assertOk()->assertSee('Bia Tosadora')->callAction('export');
    }

    public function test_public_booking_page_and_the_clients_link(): void
    {
        $owner = $this->owner;
        $this->get(route('agenda.booking'))->assertOk()->assertSee('Banho')->assertSee(__('agenda.booking.anyone'));
        $this->get(route('agenda.booking', ['service' => $this->bath->getKey(), 'size' => 'small', 'date' => '2026-11-04']))
            ->assertOk()->assertSee('08:00')->assertSee('R$');

        $form = [
            'service' => $this->bath->getKey(), 'size' => 'small', 'date' => '2026-11-04', 'time' => '09:00',
            'name' => 'João Novo', 'phone' => '(21) 97777-6666', 'pet' => 'Mel', 'species' => 'cat', 'breed' => 'SRD',
            'started' => Crypt::encryptString((string) (now()->getTimestamp() - 10)),
        ];

        // Robots: the hidden field or too fast.
        $this->post(route('agenda.booking.store'), $form + ['website' => 'x'])->assertSessionHasErrors('name');
        $this->post(route('agenda.booking.store'), ['started' => Crypt::encryptString((string) now()->getTimestamp())] + $form)->assertSessionHasErrors('name');
        $this->assertSame(0, Appointment::count());

        $response = $this->post(route('agenda.booking.store'), $form);
        $response->assertSessionHasNoErrors();
        $appointment = Appointment::query()->sole();
        $response->assertRedirect(route('agenda.manage', ['token' => $appointment->token]));
        $this->assertSame([Appointment::ONLINE, 45, '5521977776666', 'Mel', ItemSize::Small], [$appointment->origin, $appointment->minutes(), $appointment->client->phone, $appointment->item->name, $appointment->item->size]);
        $this->assertSame(1, $owner->notifications()->count());

        // The same time is gone; the same phone finds the same client and pet.
        $this->post(route('agenda.booking.store'), $form)->assertOk()->assertSee(__('agenda.errors.slot_taken'));
        $this->post(route('agenda.booking.store'), ['time' => '10:00', 'name' => 'Outro nome', 'pet' => 'mel'] + $form);
        $this->assertSame(1, Client::query()->where('phone', '5521977776666')->count());
        $this->assertSame(1, Item::query()->where('name', 'Mel')->count());

        // The client's link: confirm, reschedule, cancel.
        $link = route('agenda.manage', ['token' => $appointment->token]);
        $this->get($link)->assertOk()->assertSee('Mel')->assertSee(__('agenda.manage.confirm'));
        $this->post($link, ['action' => 'confirm'])->assertRedirect($link);
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);

        $this->get($link.'?day=2026-11-05')->assertOk()->assertSee('13:00');
        $this->post($link, ['action' => 'move', 'day' => '2026-11-05', 'time' => '13:00'])->assertRedirect($link);
        $this->assertSame('2026-11-05 13:00', $appointment->fresh()->starts_at->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));

        $this->post($link, ['action' => 'cancel', 'reason' => 'Viagem'])->assertRedirect($link);
        $appointment->refresh();
        $this->assertSame([AppointmentStatus::Canceled, 'client', 'Viagem'], [$appointment->status, $appointment->canceled_by, $appointment->cancel_reason]);
        $this->assertGreaterThanOrEqual(4, $owner->notifications()->count());

        // Too late to change by the link (less than 4 hours before).
        $late = $this->book('2026-11-03 10:00');
        $lateLink = route('agenda.manage', ['token' => $late->token]);
        $this->get($lateLink)->assertOk()->assertDontSee(__('agenda.manage.cancel'));
        $this->post($lateLink, ['action' => 'cancel'])->assertRedirect($lateLink);
        $this->assertSame(AppointmentStatus::Scheduled, $late->fresh()->status);

        // Booking closed: the page says so.
        AgendaSetting::current()->update(['online_booking' => false]);
        $this->get(route('agenda.booking'))->assertOk()->assertSee(__('agenda.booking.closed'));
    }

    public function test_the_plan_limits_the_professionals(): void
    {
        $this->professional('Bia');
        $this->professional('Caio');
        $this->assertFalse(ProfessionalResource::canCreate());

        $this->expectException(PersonRuleException::class);
        app(AgendaPeople::class)->check(Person::create(['name' => 'Duda']), ['attends' => true]);
    }

    public function test_roles_desk_professional_and_viewer(): void
    {
        $bia = $this->professional('Bia');
        $mine = $this->book('2026-11-03 10:00', [], $bia);
        $other = $this->book('2026-11-03 10:00');

        $desk = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($desk, 'agenda', 'desk');
        $this->actingAs($desk);
        $this->assertTrue(AppointmentResource::canCreate());
        $this->assertTrue(ClientResource::canCreate());
        $this->assertFalse(ServiceResource::canCreate());
        $this->assertFalse(AgendaSettingsPage::canAccess());
        $this->assertTrue(RemindersPage::canAccess());
        Livewire::test(RemindersPage::class)->assertOk();

        // A professional with a login: only their own appointments, may move them along.
        $groomer = User::factory()->memberOf($this->company, 'member')->create();
        $bia->person->forceFill(['user_id' => $groomer->getKey()])->save();
        app(AccessManager::class)->forget();
        $this->actingAs($groomer);
        $this->assertSame('professional', app(AccessManager::class)->role($groomer, 'agenda'));
        $this->assertFalse(AppointmentResource::canCreate());
        $this->assertSame([$mine->getKey()], AppointmentResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue(AppointmentResource::can('work', $mine));
        $this->assertFalse(AppointmentResource::can('work', $other));
        Livewire::test(CalendarPage::class)->assertOk()->assertSee('Bia')->assertDontSee('Ana Banhista');

        $viewer = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($viewer, 'agenda', 'viewer');
        $this->actingAs($viewer);
        $this->assertTrue(AppointmentResource::canViewAny());
        $this->assertFalse(AppointmentResource::canCreate());
        $this->assertFalse(AppointmentResource::can('work', $other));
    }

    public function test_screens_open_and_create(): void
    {
        Livewire::test(AgendaSettingsPage::class)->call('save')->assertHasNoErrors();
        Livewire::test(ListAppointments::class)->assertOk();
        Livewire::test(ManageProfessionals::class)->assertOk()->assertSee('Ana Banhista')
            ->callTableAction('setup', $this->ana, ['commission_bp' => '40', 'color' => '#0F766E', 'online' => true, 'follows_time_clock' => true, 'week' => WorkingHours::defaultWeek(), 'services' => []])
            ->assertHasNoTableActionErrors();
        $this->assertSame(4000, $this->ana->fresh()->commission_bp);

        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'Carla', 'phone' => '(31) 98888-1111', 'items' => [['name' => 'Bob', 'species' => 'dog', 'size' => 'large']]])
            ->call('create')
            ->assertHasNoFormErrors();
        $carla = Client::query()->where('name', 'Carla')->sole();
        $this->assertSame(['5531988881111', 'Bob', Client::PERSON], [$carla->phone, $carla->items->first()->name, $carla->kind]);
        $this->get(ClientResource::getUrl('view', ['record' => $carla]))->assertOk()->assertSee('Bob');

        // A client with history is never deleted.
        $this->book('2026-11-03 10:00');
        $this->assertFalse(ClientResource::canDelete($this->maria));
    }

    public function test_pick_up_becomes_a_campo_work_order(): void
    {
        $this->company = $this->tenant('petcampo', ['agenda' => [], 'campo' => []]);
        $this->owner = $this->signIn($this->company);
        FieldSetting::current()->update(['business' => 'general', 'configured_at' => now()]);
        app(FieldTemplates::class)->apply('general');
        AgendaSetting::current()->update(['field_service_type_id' => ServiceType::query()->value('id')]);
        app(AgendaTemplates::class)->apply('petshop');
        $this->bath = Service::query()->where('template_key', 'bath')->sole();
        $this->ana = $this->professional('Ana');
        $this->maria = new Client;
        $this->maria->forceFill(['name' => 'Maria', 'phone' => '5511988887777', 'kind' => Client::PERSON])->save();
        $this->thor = Item::create(['customer_id' => $this->maria->getKey(), 'name' => 'Thor', 'size' => ItemSize::Small]);

        $appointment = $this->book('2026-11-04 10:00', [
            'service_ids' => [$this->bath->getKey(), Service::query()->where('template_key', 'pickup')->value('id')],
            'pickup_address' => 'Rua das Flores, 10', 'pickup_from' => '08:30', 'pickup_until' => '09:30',
        ]);
        $this->assertTrue($appointment->pickup && $appointment->dropoff);
        $this->assertSame(45, $appointment->minutes()); // the pick-up takes no time of the groomer
        $this->assertTrue(app(AgendaField::class)->canOpenFor($appointment));

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])->callAction('fieldOrder');
        $order = WorkOrder::query()->sole();
        $this->assertSame([$order->getKey(), $this->maria->getKey(), $appointment->pickup_site_id], [$appointment->fresh()->field_order_id, $order->customer_id, $order->site_id]);
        $this->assertStringContainsString('Thor', $order->reported);
        $this->assertFalse(app(AgendaField::class)->canOpenFor($appointment->fresh()));
    }

    public function test_each_company_only_sees_its_own_agenda(): void
    {
        $appointment = $this->book('2026-11-04 10:00');
        $other = $this->tenant('outropet', ['agenda' => []]);

        app(TenantContext::class)->run($other, function (): void {
            $this->assertSame(0, Appointment::count());
            $this->assertNull(Client::query()->find($this->maria->getKey()));
            $this->assertSame([0, 0, 0], [Professional::count(), Service::count(), Item::count()]);
        });

        // The client's link does not exist on another company's address.
        app(TenantContext::class)->set($other);
        $this->get($other->url('/agendar/'.$appointment->token))->assertNotFound();

        // The database refuses a pet of one company for a client of another.
        $this->expectException(QueryException::class);
        DB::table('ag_items')->insert(['tenant_id' => $other->id, 'customer_id' => $this->maria->getKey(), 'name' => 'Intruso', 'kind' => 'pet', 'created_at' => now(), 'updated_at' => now()]);
    }
}
