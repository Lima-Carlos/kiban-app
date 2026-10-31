<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ReceiptService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Kiban Core Ponto without signal, like WhatsApp: the phone keeps each punch in a queue and sends it when the signal
 * comes back. The punch keeps the time it was taken, is flagged "without internet" for the manager and shows when
 * it arrived; the same punch sent twice counts once; too old or in a closed month, it is refused with the reason.
 */
class TimeClockOfflineTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-28 14:00:00');
        $this->company = $this->tenant('instaladora', ['ponto' => []]);
        $this->owner = $this->signIn($this->company);
        $office = Workplace::create(['name' => 'Escritório', 'timezone' => 'America/Sao_Paulo']);
        $schedule = Schedule::create(['name' => 'Comercial', 'type' => Schedule::WEEKLY, 'week' => ScheduleService::presets()['office']['week']]);
        $person = app(PeopleService::class)->add($this->company, ['name' => 'Paulo', 'phone' => '11977776666', 'document' => '529.982.247-25'], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $office->id, 'schedule_id' => $schedule->id,
        ]], $this->owner)['person'];
        $this->employee = Employee::query()->where('person_id', $person->id)->sole();
        $this->actingAs($person->user);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(array $data = []): TestResponse
    {
        return $this->postJson($this->company->url('/ponto/enviar'), $data + ['id' => (string) Str::uuid(), 'taken_at' => now()->getTimestampMs(), 'latitude' => -23.55, 'longitude' => -46.63]);
    }

    public function test_a_punch_sent_at_once_keeps_the_server_time_and_counts_once(): void
    {
        $id = (string) Str::uuid();

        // The phone's clock is 1 minute late: the server's time wins.
        $this->send(['id' => $id, 'taken_at' => now()->subMinute()->getTimestampMs()])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'time' => '11:00', 'nsr' => 1, 'offline' => false]);

        $punch = Punch::sole();
        $this->assertSame('2026-10-28 14:00:00', $punch->punched_at->toDateTimeString());
        $this->assertSame($id, $punch->client_id);
        $this->assertNotContains(Punch::OFFLINE, $punch->flags);

        // The answer was lost and the phone sends it again: the same punch, not a new one.
        $this->travel(5)->minutes();
        $this->send(['id' => $id])->assertJson(['status' => 'ok', 'nsr' => 1]);
        $this->assertSame(1, Punch::count());
    }

    public function test_a_punch_kept_without_signal_keeps_the_time_it_was_taken(): void
    {
        $takenAt = now()->subHours(3);

        $this->send(['taken_at' => $takenAt->getTimestampMs()])->assertJson(['status' => 'ok', 'time' => '08:00', 'offline' => true]);

        $punch = Punch::sole();
        $this->assertSame('2026-10-28 11:00:00', $punch->punched_at->toDateTimeString());
        $this->assertSame('2026-10-28 14:00:00', $punch->created_at->toDateTimeString());
        $this->assertContains(Punch::OFFLINE, $punch->flags);
        // The chain of punches stays right.
        $this->assertNull(app(PunchService::class)->brokenLink());

        // The receipt says when it was taken and when it arrived.
        $this->get(app(ReceiptService::class)->url($punch))
            ->assertOk()
            ->assertSee('08:00:00')
            ->assertSee(__('timeclock.receipt.received'))
            ->assertSee('11:00:00')
            ->assertSee(__('timeclock.receipt.offline'));

        // The manager sees the warning in the list of punches.
        $this->assertSame(__('timeclock.punches.flags.offline'), __('timeclock.punches.flags.'.Punch::OFFLINE));
    }

    public function test_two_punches_kept_without_signal_arrive_in_their_order(): void
    {
        $this->send(['taken_at' => now()->subHours(5)->getTimestampMs()])->assertJson(['status' => 'ok', 'time' => '06:00']);
        $this->send(['taken_at' => now()->subHours(4)->getTimestampMs()])->assertJson(['status' => 'ok', 'time' => '07:00']);

        // A second tap of the same moment is not a new punch.
        $this->send(['taken_at' => now()->subHours(4)->addSeconds(20)->getTimestampMs()])
            ->assertJson(['status' => 'refused', 'message' => __('timeclock.errors.double_tap', ['time' => '07:00'])]);

        $this->assertSame(['06:00', '07:00'], Punch::query()->orderBy('punched_at')->get()->map(fn (Punch $punch): string => $punch->localTime()->format('H:i'))->all());
    }

    public function test_too_old_or_from_the_future(): void
    {
        $this->send(['taken_at' => now()->subDays(PunchService::OFFLINE_MAX_DAYS + 1)->getTimestampMs()])
            ->assertJson(['status' => 'refused', 'message' => __('timeclock.errors.offline_too_old', ['days' => PunchService::OFFLINE_MAX_DAYS])]);
        $this->assertSame(0, Punch::count());

        // A phone clock ahead of time never puts a punch in the future.
        $this->send(['taken_at' => now()->addHour()->getTimestampMs()])->assertJson(['status' => 'ok', 'time' => '11:00', 'offline' => false]);
    }

    public function test_a_punch_of_a_closed_month_is_refused(): void
    {
        // October was closed on the 1st of November; a punch of 31/10 kept on the phone arrives on the 2nd.
        $this->travelTo('2026-11-02 12:00:00');
        app(MonthClosing::class)->close($this->employee, CarbonImmutable::parse('2026-10-01'), $this->owner);

        $this->send(['taken_at' => CarbonImmutable::parse('2026-10-31 20:00:00')->getTimestampMs()])
            ->assertJson(['status' => 'refused', 'message' => __('timeclock.errors.offline_month_closed')]);
        $this->assertSame(0, Punch::count());
    }

    public function test_only_the_signed_in_employee_sends(): void
    {
        $this->send(['id' => 'not-a-code'])->assertUnprocessable();

        auth()->logout();
        $this->send()->assertUnauthorized();

        $this->getJson($this->company->url('/ponto/hora'))->assertUnauthorized();
        $this->assertSame(0, Punch::count());
    }

    public function test_the_page_keeps_punches_on_the_phone(): void
    {
        $this->getJson($this->company->url('/ponto/hora'))->assertOk()->assertJson(['now' => now()->getTimestampMs()])->assertJsonStructure(['token']);

        $this->get($this->company->url('/ponto/bater-ponto'))
            ->assertOk()
            ->assertSee('js/punch-queue.js', false)
            ->assertSee(trim(json_encode($this->company->url('/ponto/enviar')), '"'), false);

        $this->assertStringContainsString('bater-ponto', file_get_contents(public_path('sw.js')));
    }
}
