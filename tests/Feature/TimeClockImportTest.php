<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Afd\AfdImport;
use App\Modules\TimeClock\Afd\AfdImportException;
use App\Modules\TimeClock\Afd\AfdLine;
use App\Modules\TimeClock\Filament\Punches\Pages\ListPunches;
use App\Modules\TimeClock\Models\Clock;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\AejFile;
use App\Modules\TimeClock\Services\AfdFile;
use App\Modules\TimeClock\Services\DayCalculator;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Brazil: the punches of a time clock the company already has, by its AFD file (Portaria 671 with CPF, and the
 * older Portaria 1510 with PIS). They count in the day, are not repeated when the same file comes again, are
 * not in this program's AFD and appear in the AEJ with the clock.
 */
class TimeClockImportTest extends TestCase
{
    use RefreshDatabase;

    protected const TZ = 'America/Sao_Paulo';

    protected Tenant $company;

    protected User $owner;

    protected Employee $rui;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('instaladora', ['ponto' => []]);
        $this->owner = $this->signIn($this->company);
        FilamentTimezone::set(self::TZ);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', self::TZ));
        $office = Workplace::create(['name' => 'Escritório', 'timezone' => self::TZ]);
        $schedule = Schedule::create(['name' => 'office'] + ScheduleService::presets()['office'] + ['anchor_date' => '2026-11-02']);
        $person = app(PeopleService::class)->add($this->company, ['name' => 'Rui', 'phone' => '11977776666', 'document' => '529.982.247-25'], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-11-01', 'workplace_id' => $office->id, 'schedule_id' => $schedule->id, 'pis' => '120.54312.78-1',
        ]], $this->owner, false)['person'];
        $this->rui = Employee::query()->where('person_id', $person->id)->sole();
        TimeClockSetting::current()->forceFill(['employer_document_type' => 'cnpj', 'employer_document' => '11222333000181', 'employer_name' => 'Instaladora Ltda'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', self::TZ));
    }

    /** An AFD of a REP-C (Portaria 671): header with the serial number, punches (type 3) with CPF and CRC. */
    protected function afd671(array $punches): string
    {
        $at = CarbonImmutable::parse('2026-11-04 19:00', self::TZ);
        $header = '000000000'.'1'.'1'.'11222333000181'.str_repeat(' ', 14).AfdLine::text('Instaladora Ltda', 150).AfdLine::text('00004004330012345', 17)
            .'2026-11-01'.'2026-11-04'.AfdLine::dateTime($at).'003'.'1'.AfdLine::text('11222333000181', 14).AfdLine::text('Relogio X', 30);
        $lines = [AfdLine::withCrc($header)];

        foreach ($punches as $nsr => [$moment, $cpf]) {
            $lines[] = AfdLine::withCrc(AfdLine::nsr($nsr).'3'.AfdLine::dateTime(CarbonImmutable::parse($moment, self::TZ)).'0'.$cpf);
        }

        $lines[] = AfdLine::trailer([3 => count($punches)]);
        $lines[] = AfdLine::signature();

        return AfdLine::latin1(implode("\r\n", $lines)."\r\n");
    }

    public function test_imports_a_portaria_671_file(): void
    {
        $file = $this->afd671([
            10 => ['2026-11-03 08:00', '52998224725'],
            11 => ['2026-11-03 12:00', '52998224725'],
            12 => ['2026-11-03 13:00', '52998224725'],
            13 => ['2026-11-03 18:00', '52998224725'],
            14 => ['2026-11-03 08:05', '11144477735'],
        ]);

        $result = app(AfdImport::class)->import($file, 'Relógio da portaria');
        $this->assertSame([4, 0, 0, ['CPF 111.444.777-35']], [$result['imported'], $result['duplicates'], $result['closed'], $result['unknown']]);
        $clock = Clock::sole();
        $this->assertSame(['Relógio da portaria', '00004004330012345', Clock::REP_C, 14], [$clock->name, $clock->number, $clock->type, $clock->last_nsr]);

        $punch = Punch::query()->where('clock_nsr', 10)->sole();
        $this->assertSame([null, Punch::CLOCK, '2026-11-03 08:00'], [$punch->nsr, $punch->source, $punch->localTime()->format('Y-m-d H:i')]);

        // The day counts the 9 hours; this program's chain is still right.
        $day = app(DayCalculator::class)->period($this->rui, CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-03'))['2026-11-03'];
        $this->assertSame([540, false], [$day['worked'], $day['absence']]);
        $this->assertNull(app(PunchService::class)->brokenLink());

        // The same file again: nothing repeated.
        $again = app(AfdImport::class)->import($file);
        $this->assertSame([0, 4], [$again['imported'], $again['duplicates']]);
        $this->assertSame(4, Punch::count());

        // Not in this program's AFD (they are in the clock's), but in the AEJ with the clock (REP-C, number).
        $this->assertStringNotContainsString('2026-11-03T08:00:00-0300', app(AfdFile::class)->contents(CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-03')));
        $aej = preg_split('/\R/', rtrim(mb_convert_encoding(app(AejFile::class)->contents(CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-03')), 'UTF-8', 'ISO-8859-1')));
        $this->assertContains('02|2|1|00004004330012345', $aej);
        $this->assertContains('05|1|2026-11-03T08:00:00-0300|2|E|1|O|H01|', $aej);
    }

    public function test_imports_an_old_portaria_1510_file_by_pis(): void
    {
        $header = '000000000'.'1'.'1'.'11222333000181'.str_repeat('0', 12).AfdLine::text('Instaladora Ltda', 150).'00012345678901234'.'01112026'.'04112026'.'04112026'.'1900';
        $file = implode("\r\n", [$header, '000000021'.'3'.'03112026'.'0800'.'012054312781', '000000022'.'3'.'03112026'.'1200'.'012054312781', '999999999'.'000000000'.'000000002'.'000000000'.'000000000'.'9'])."\r\n";

        $result = app(AfdImport::class)->import($file);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(['2026-11-03 08:00', '2026-11-03 12:00'], Punch::query()->orderBy('punched_at')->get()->map(fn (Punch $punch): string => $punch->localTime()->format('Y-m-d H:i'))->all());
        $this->assertSame('Relógio 00012345678901234', Clock::sole()->name);
    }

    public function test_a_file_that_is_not_an_afd(): void
    {
        $this->expectException(AfdImportException::class);
        app(AfdImport::class)->import("Nome;Data;Hora\nRui;03/11/2026;08:00\n");
    }

    public function test_the_import_button(): void
    {
        // After the date jump: a fresh license and a new sign-in.
        $this->company->forceFill(['license_token' => $this->licenseToken($this->company, ['ponto' => []]), 'license_fetched_at' => now(), 'license_expires_at' => now()->addHours(72)])->save();
        $this->signIn($this->company->fresh());
        Livewire::test(ListPunches::class)->assertActionVisible('importAfd');

        TimeClockSetting::current()->forceFill(['labor_profile' => 'mx_lft'])->save();
        Livewire::test(ListPunches::class)->assertActionHidden('importAfd');
    }
}
