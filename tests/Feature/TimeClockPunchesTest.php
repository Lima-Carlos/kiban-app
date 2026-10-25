<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Filament\Kiosks\Pages\ManageKiosks;
use App\Modules\TimeClock\Filament\PunchPage;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Kiosk;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\KioskService;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ReceiptService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use App\People\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Kiban Ponto, part 3: punches on the phone (live selfie + location) and on the company tablet (PIN +
 * photo), never blocked but flagged, numbered (NSR) in a chain of hashes, never changed; the receipt at a
 * signed address with a verification code; tablets activated by the manager (who is signed out of them).
 */
class TimeClockPunchesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected Workplace $office;

    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->company = $this->tenant('instaladora', ['ponto' => []]);
        $this->owner = $this->signIn($this->company);
        $this->office = Workplace::create(['name' => 'Escritório', 'timezone' => 'America/Sao_Paulo', 'latitude' => -23.5505, 'longitude' => -46.6333, 'radius_meters' => 200]);
        $this->schedule = Schedule::create(['name' => 'Comercial', 'type' => Schedule::WEEKLY, 'week' => ScheduleService::presets()['office']['week']]);
    }

    /** An employee with a login (clocks in on the phone) or without (only the tablet). */
    protected function employee(string $name = 'Paulo', bool $withLogin = true, string $cpf = '529.982.247-25'): Employee
    {
        $result = app(PeopleService::class)->add($this->company, ['name' => $name, 'phone' => '11977776666', 'document' => $cpf], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $this->office->id, 'schedule_id' => $this->schedule->id,
        ]], $this->owner, $withLogin);

        return Employee::query()->where('person_id', $result['person']->id)->sole();
    }

    protected function selfie(): string
    {
        return 'data:image/jpeg;base64,'.base64_encode(UploadedFile::fake()->image('selfie.jpg', 40, 40)->getContent());
    }

    public function test_clock_in_on_the_phone(): void
    {
        $employee = $this->employee();
        $user = $employee->person->user;
        $this->actingAs($user);

        // Inside the radius (about 50 m away), with selfie (after the photo notice): no warnings.
        Livewire::test(PunchPage::class)
            ->call('acknowledgePhotoNotice')
            ->call('punch', $this->selfie(), -23.5509, -46.6333, 12.0)
            ->assertNotified();

        $punch = Punch::sole();
        $this->assertSame(1, $punch->nsr);
        $this->assertSame(Punch::PHONE, $punch->source);
        $this->assertSame($user->id, $punch->user_id);
        $this->assertSame([], $punch->flags);
        $this->assertLessThan(200, $punch->distance_meters);
        $this->assertSame('America/Sao_Paulo', $punch->timezone);
        Storage::disk('local')->assertExists($punch->photo_path);
        $this->assertStringStartsWith("tenants/{$this->company->id}/timeclock/punches/", $punch->photo_path);

        // A second tap in the same minute is not a new punch.
        Livewire::test(PunchPage::class)->call('punch', $this->selfie(), -23.5509, -46.6333, 12.0);
        $this->assertSame(1, Punch::count());

        // Far away, without photo, without location: recorded anyway, with the warnings.
        $this->travel(2)->minutes();
        Livewire::test(PunchPage::class)->call('punch', null, -22.9068, -43.1729, 20.0);
        $this->travel(2)->minutes();
        Livewire::test(PunchPage::class)->call('punch', $this->selfie(), null, null, null);
        $this->assertSame([Punch::OUTSIDE_RADIUS, Punch::NO_PHOTO], Punch::query()->where('nsr', 2)->sole()->flags);
        $this->assertSame([Punch::NO_LOCATION], Punch::query()->where('nsr', 3)->sole()->flags);

        // The chain of hashes is whole; the page shows today's punches and the Home has the button.
        $this->assertNull(app(PunchService::class)->brokenLink());
        $this->get($this->company->url('/ponto/bater-ponto'))->assertOk()->assertSee(__('timeclock.punches.punch_button'));
        $this->get($this->company->url('/'))->assertOk()->assertSee(__('timeclock.home.title'));
    }

    public function test_external_employee_has_no_radius(): void
    {
        $employee = $this->employee();
        $employee->forceFill(['is_external' => true, 'workplace_id' => null])->save();
        app(PunchService::class)->punch($employee, ['latitude' => -22.9068, 'longitude' => -43.1729, 'photo_path' => 'x.jpg'], $employee->person->user);

        $punch = Punch::sole();
        $this->assertSame([], $punch->flags);
        $this->assertNull($punch->distance_meters);
        $this->assertSame(-22.9068, $punch->latitude);
    }

    public function test_punches_never_change(): void
    {
        $employee = $this->employee();
        $punch = app(PunchService::class)->punch($employee, ['photo_path' => 'tenants/x/a.jpg', 'latitude' => -23.55, 'longitude' => -46.63]);

        $this->assertThrows(fn () => $punch->forceFill(['punched_at' => now()->subHour()])->save(), LogicException::class);
        $this->assertThrows(fn () => $punch->fresh()->delete(), LogicException::class);

        // Only the old photo may go away (retention).
        $punch->fresh()->forceFill(['photo_path' => null])->save();
        $this->assertNull($punch->fresh()->photo_path);

        // Two companies number their punches apart, each from 1.
        $other = $this->tenant('outra', ['ponto' => []]);
        $owner = $this->signIn($other);
        $place = Workplace::create(['name' => 'Loja', 'timezone' => 'America/Sao_Paulo']);
        $schedule = Schedule::create(['name' => 'Comercial', 'type' => Schedule::WEEKLY, 'week' => ScheduleService::presets()['office']['week']]);
        $person = app(PeopleService::class)->add($other, ['name' => 'Ana', 'document' => '111.444.777-35'], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $place->id, 'schedule_id' => $schedule->id,
        ]], $owner, false)['person'];
        $theirs = app(PunchService::class)->punch(Employee::query()->where('person_id', $person->id)->sole(), ['photo_path' => 'b.jpg']);
        $this->assertSame(1, $theirs->nsr);
        $this->assertSame(1, Punch::count());
    }

    public function test_the_tablet_time_clock(): void
    {
        $employee = $this->employee('Bia', false);
        $kiosk = Kiosk::create(['name' => 'Recepção', 'workplace_id' => $this->office->id]);
        $pin = app(KioskService::class)->newPin($employee);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertArrayNotHasKey('pin_lookup', $employee->fresh()->toArray());

        // Not activated: the page says so.
        auth()->logout();
        $this->get($this->company->url('/relogio'))->assertOk()->assertSee(__('timeclock.kiosk.inactive'));

        $secret = app(KioskService::class)->activate($kiosk);
        $this->withCookie(KioskService::COOKIE, $secret);
        $this->get($this->company->url('/relogio'))->assertOk()->assertSee(__('timeclock.kiosk.type_pin'));

        $this->post($this->company->url('/relogio/pin'), ['pin' => '000000'])->assertSee(__('timeclock.kiosk.wrong_pin'));
        $page = $this->post($this->company->url('/relogio/pin'), ['pin' => $pin])->assertOk()->assertSee(__('timeclock.kiosk.hello', ['name' => 'Bia']));
        // First time: the photo notice, then the photo.
        preg_match('/name="ticket" value="([^"]+)"/', $page->getContent(), $match);
        $page = $this->post($this->company->url('/relogio/ciente'), ['ticket' => html_entity_decode($match[1])])->assertOk()->assertSee(__('timeclock.kiosk.photo_help'));
        preg_match('/name="ticket" value="([^"]+)"/', $page->getContent(), $match);

        $this->post($this->company->url('/relogio/bater'), ['ticket' => html_entity_decode($match[1]), 'photo' => $this->selfie()])
            ->assertOk()
            ->assertSee(__('timeclock.kiosk.done', ['time' => now('America/Sao_Paulo')->format('H:i')]))
            ->assertSee('<svg', false);

        $punch = Punch::sole();
        $this->assertSame(Punch::KIOSK, $punch->source);
        $this->assertSame($kiosk->id, $punch->kiosk_id);
        $this->assertNull($punch->user_id);
        $this->assertSame(-23.5505, $punch->latitude); // the tablet's place
        $this->assertNotContains(Punch::OUTSIDE_RADIUS, $punch->flags);
        $this->assertNotNull($kiosk->fresh()->last_seen_at);

        // The ticket is used only within a few minutes.
        $this->travel(4)->minutes();
        $this->post($this->company->url('/relogio/bater'), ['ticket' => html_entity_decode($match[1]), 'photo' => $this->selfie()])->assertSee(__('timeclock.kiosk.expired'));

        // Ten wrong codes in a minute: the tablet waits.
        for ($i = 0; $i < 10; $i++) {
            $this->post($this->company->url('/relogio/pin'), ['pin' => '000000']);
        }
        $this->post($this->company->url('/relogio/pin'), ['pin' => $pin])->assertSee(__('timeclock.kiosk.too_many', ['seconds' => 60]), false);

        // Deactivated: it stops at once.
        app(KioskService::class)->deactivate($kiosk);
        $this->get($this->company->url('/relogio'))->assertSee(__('timeclock.kiosk.inactive'));
    }

    public function test_activating_a_tablet_signs_the_manager_out(): void
    {
        $kiosk = Kiosk::create(['name' => 'Recepção', 'workplace_id' => $this->office->id]);

        Livewire::test(ManageKiosks::class)
            ->callTableAction('activate', $kiosk)
            ->assertRedirect(url('/relogio'));

        $this->assertTrue($kiosk->fresh()->isActivated());
        $this->assertTrue(Cookie::hasQueued(KioskService::COOKIE));
        $this->assertGuest();
    }

    public function test_receipt_at_a_signed_address(): void
    {
        $employee = $this->employee();
        $punch = app(PunchService::class)->punch($employee, ['photo_path' => 'a.jpg']);
        $receipts = app(ReceiptService::class);
        $url = $receipts->url($punch);
        $this->assertStringStartsWith('http://instaladora.kiban.test/ponto/comprovante/'.$punch->id.'?', $url);

        auth()->logout();
        $this->get($url)->assertOk()->assertSee($receipts->verificationCode($punch))->assertSee('Paulo')->assertSee('CPF 529.982.247-25');
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        $pdf = $this->get($this->company->url(ltrim(URL::signedRoute('timeclock.receipt.pdf', ['punch' => $punch->id], absolute: false), '/')));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', $receipts->verificationCode($punch));
    }

    public function test_who_sees_what(): void
    {
        $employee = $this->employee();
        $this->actingAs($employee->person->user);
        $this->get($this->company->url('/ponto/bater-ponto'))->assertOk();
        $this->get($this->company->url('/ponto/batidas'))->assertForbidden();
        $this->get($this->company->url('/ponto/relogios'))->assertForbidden();

        $viewer = User::factory()->memberOf($this->company)->create();
        app(AccessManager::class)->set($viewer, 'ponto', 'viewer');
        $this->actingAs($viewer);
        $this->get($this->company->url('/ponto/batidas'))->assertOk();
        $this->get($this->company->url('/ponto/relogios'))->assertForbidden();
        $this->get($this->company->url('/ponto/bater-ponto'))->assertForbidden(); // does not clock in

        $this->actingAs($this->owner);
        $this->get($this->company->url('/ponto/batidas'))->assertOk();
        $this->get($this->company->url('/ponto/relogios'))->assertOk();
        $this->assertSame(0, Person::query()->where('user_id', $viewer->id)->count()); // People records appear on the People screen
    }
}
