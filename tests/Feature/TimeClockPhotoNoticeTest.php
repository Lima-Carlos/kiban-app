<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Filament\PunchPage;
use App\Modules\TimeClock\Filament\TimeClockSettingsPage;
use App\Modules\TimeClock\Filament\TimesheetPage;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Kiosk;
use App\Modules\TimeClock\Models\PhotoNotice;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\KioskService;
use App\Modules\TimeClock\Services\PhotoNoticeService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Kiban Ponto: the privacy notice of the punch photos (LGPD). Before the first photo the person reads the notice
 * and taps "I am aware" (recorded, never changed; a new version asks again); without it no photo is stored;
 * "Punch without photo" when the company allows it; the punch is never blocked.
 */
class TimeClockPhotoNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected Workplace $office;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->company = $this->tenant('instaladora', ['ponto' => []]);
        $this->owner = $this->signIn($this->company);
        $this->office = Workplace::create(['name' => 'Escritório', 'timezone' => 'America/Sao_Paulo']);
    }

    protected function employee(string $name = 'Paulo', bool $withLogin = true, string $cpf = '529.982.247-25'): Employee
    {
        $schedule = Schedule::query()->first() ?? Schedule::create(['name' => 'Comercial', 'type' => Schedule::WEEKLY, 'week' => ScheduleService::presets()['office']['week']]);
        $result = app(PeopleService::class)->add($this->company, ['name' => $name, 'phone' => '11977776666', 'document' => $cpf], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $this->office->id, 'schedule_id' => $schedule->id,
        ]], $this->owner, $withLogin);

        return Employee::query()->where('person_id', $result['person']->id)->sole();
    }

    protected function selfie(): string
    {
        return 'data:image/jpeg;base64,'.base64_encode(UploadedFile::fake()->image('selfie.jpg', 40, 40)->getContent());
    }

    public function test_the_phone_asks_once_before_the_first_photo(): void
    {
        $employee = $this->employee();
        $user = $employee->person->user;
        $this->actingAs($user);

        // The notice comes first (why, who sees it, how long, no face recognition, whom to ask).
        $this->get($this->company->url('/ponto/bater-ponto'))->assertOk()
            ->assertSee(__('timeclock.privacy.title'))
            ->assertSee(__('timeclock.privacy.acknowledge'))
            ->assertSee(__('timeclock.privacy.contact_default'))
            ->assertSee(__('timeclock.privacy.without_photo'));

        // A photo sent before the acknowledgement is not stored; the punch counts anyway.
        $page = Livewire::test(PunchPage::class)->call('punch', $this->selfie(), null, null, null)->assertNotified();
        $punch = Punch::sole();
        $this->assertNull($punch->photo_path);
        $this->assertContains(Punch::NO_PHOTO, $punch->flags);
        $this->assertSame([], Storage::disk('local')->allFiles('tenants'));

        // "I am aware": recorded with who, how, where; asked only once.
        $page->call('acknowledgePhotoNotice')->call('acknowledgePhotoNotice');
        $notice = PhotoNotice::sole();
        $this->assertSame([$employee->id, Punch::PHONE, $user->id, 12], [$notice->employee_id, $notice->source, $notice->user_id, $notice->retention_months]);
        $this->assertSame(app(PhotoNoticeService::class)->version(), $notice->version);
        $this->assertFalse(Livewire::test(PunchPage::class)->instance()->needsPhotoNotice());

        $this->travel(2)->minutes();
        Livewire::test(PunchPage::class)->call('punch', $this->selfie(), null, null, null);
        Storage::disk('local')->assertExists(Punch::query()->latest('nsr')->first()->photo_path);

        // A new contact is a new version of the notice: read again.
        TimeClockSetting::current()->forceFill(['privacy_contact' => 'rh@instaladora.com.br'])->save();
        $this->assertTrue(Livewire::test(PunchPage::class)->instance()->needsPhotoNotice());
        $this->get($this->company->url('/ponto/bater-ponto'))->assertSee(__('timeclock.privacy.contact', ['contact' => 'rh@instaladora.com.br']));
        $this->assertStringContainsString(__('timeclock.privacy.outdated'), app(PhotoNoticeService::class)->status($employee, 'America/Sao_Paulo'));
        Livewire::test(PunchPage::class)->call('acknowledgePhotoNotice');
        $this->assertSame(2, PhotoNotice::count());

        // The acknowledgement is a record of fact.
        $this->expectException(LogicException::class);
        $notice->delete();
    }

    public function test_punch_without_photo_when_the_company_allows(): void
    {
        $employee = $this->employee();
        $this->actingAs($employee->person->user);

        Livewire::test(PunchPage::class)->call('punch', null, null, null, null, true);
        $this->assertContains(Punch::PHOTO_DECLINED, Punch::sole()->flags);
        $this->assertNotContains(Punch::NO_PHOTO, Punch::sole()->flags);
        // A photo is never taken with "without photo", even after the notice.
        Livewire::test(PunchPage::class)->call('acknowledgePhotoNotice');
        $this->travel(2)->minutes();
        Livewire::test(PunchPage::class)->call('punch', $this->selfie(), null, null, null, true);
        $this->assertNull(Punch::query()->latest('nsr')->first()->photo_path);

        // The company hides the button: no button; the punch still counts (never blocked), flagged "no photo".
        TimeClockSetting::current()->forceFill(['allow_punch_without_photo' => false])->save();
        $this->get($this->company->url('/ponto/bater-ponto'))->assertOk()->assertDontSee(__('timeclock.privacy.without_photo'));
        $this->travel(2)->minutes();
        Livewire::test(PunchPage::class)->call('punch', null, null, null, null, true);
        $last = Punch::query()->latest('nsr')->first();
        $this->assertSame(3, Punch::count());
        $this->assertContains(Punch::NO_PHOTO, $last->flags);
        $this->assertNotContains(Punch::PHOTO_DECLINED, $last->flags);
    }

    public function test_the_tablet_asks_each_person_once(): void
    {
        $bia = $this->employee('Bia', false);
        $kiosk = Kiosk::create(['name' => 'Recepção', 'workplace_id' => $this->office->id]);
        $pin = app(KioskService::class)->newPin($bia);
        auth()->logout();
        $this->withCookie(KioskService::COOKIE, app(KioskService::class)->activate($kiosk));

        // The PIN opens the notice (not the camera).
        $page = $this->post($this->company->url('/relogio/pin'), ['pin' => $pin])->assertOk()
            ->assertSee(__('timeclock.privacy.title'))
            ->assertDontSee(__('timeclock.kiosk.photo_help'));
        preg_match('/name="ticket" value="([^"]+)"/', $page->getContent(), $match);
        $ticket = html_entity_decode($match[1]);

        // A photo sent without the acknowledgement is not stored.
        $this->post($this->company->url('/relogio/bater'), ['ticket' => $ticket, 'photo' => $this->selfie()])->assertOk();
        $this->assertNull(Punch::sole()->photo_path);

        // "I am aware" on the tablet: recorded for Bia, on this tablet, without a login.
        $this->travel(2)->minutes();
        $page = $this->post($this->company->url('/relogio/pin'), ['pin' => $pin]);
        preg_match('/name="ticket" value="([^"]+)"/', $page->getContent(), $match);
        $this->post($this->company->url('/relogio/ciente'), ['ticket' => html_entity_decode($match[1])])->assertOk()->assertSee(__('timeclock.kiosk.photo_help'));
        $notice = PhotoNotice::sole();
        $this->assertSame([$bia->id, Punch::KIOSK, $kiosk->id, null], [$notice->employee_id, $notice->source, $notice->kiosk_id, $notice->user_id]);

        // Next time: straight to the photo; "without photo" on the tablet too.
        $page = $this->post($this->company->url('/relogio/pin'), ['pin' => $pin])->assertSee(__('timeclock.kiosk.photo_help'))->assertSee(__('timeclock.privacy.without_photo'));
        preg_match('/name="ticket" value="([^"]+)"/', $page->getContent(), $match);
        $this->post($this->company->url('/relogio/bater'), ['ticket' => html_entity_decode($match[1]), 'without_photo' => '1'])->assertOk();
        $this->assertContains(Punch::PHOTO_DECLINED, Punch::query()->latest('nsr')->first()->flags);
    }

    public function test_who_sees_the_acknowledgement(): void
    {
        $employee = $this->employee();
        app(PhotoNoticeService::class)->record($employee, Punch::PHONE, $employee->person->user);

        // The manager: in the employees list and the time sheet of the person.
        $this->get($this->company->url('/ponto/funcionarios'))->assertOk()->assertSee(__('timeclock.privacy.column'));
        Livewire::test(TimesheetPage::class, ['employeeId' => $employee->id])
            ->assertActionVisible('photoNotice')
            ->mountAction('photoNotice')
            ->assertActionMounted('photoNotice');
        $this->assertStringStartsWith(substr(__('timeclock.privacy.aware_on', ['date' => '', 'where' => '']), 0, 8), app(PhotoNoticeService::class)->status($employee, 'America/Sao_Paulo'));
        $this->assertSame(__('timeclock.privacy.pending'), app(PhotoNoticeService::class)->status($this->employee('Ana', false, '111.444.777-35'), 'America/Sao_Paulo'));

        // The settings: the "without photo" button and the contact.
        $this->get($this->company->url('/ponto/configuracoes'))->assertOk()->assertSee(__('timeclock.settings.photos'));
        Livewire::test(TimeClockSettingsPage::class)
            ->set('data.allow_punch_without_photo', false)
            ->set('data.privacy_contact', ' rh@instaladora.com.br ')
            ->call('save')
            ->assertHasNoErrors();
        $settings = TimeClockSetting::current()->fresh();
        $this->assertFalse($settings->allow_punch_without_photo);
        $this->assertSame('rh@instaladora.com.br', $settings->privacy_contact);

        // The employee re-reads it in "My time sheet".
        $this->actingAs($employee->person->user);
        Livewire::test(TimesheetPage::class)->assertActionVisible('photoNotice');
    }
}
