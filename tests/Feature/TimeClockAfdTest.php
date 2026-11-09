<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Afd\AfdLine;
use App\Modules\TimeClock\Enums\LaborProfile;
use App\Modules\TimeClock\Models\AfdRecord;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\AdjustmentService;
use App\Modules\TimeClock\Services\AejFile;
use App\Modules\TimeClock\Services\AfdFile;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ReceiptService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Brazil, complete AFD (layout 004): the employer (type 2) and employee (type 5) records in the same numbering as the
 * punches, the official line and SHA-256 kept with each punch (and printed on the receipt), the CRC-16/KERMIT and
 * the offline flag.
 */
class TimeClockAfdTest extends TestCase
{
    use RefreshDatabase;

    protected const TZ = 'America/Sao_Paulo';

    protected Tenant $company;

    protected User $owner;

    protected Workplace $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('instaladora', ['ponto' => []]);
        $this->owner = $this->signIn($this->company);
        $this->office = Workplace::create(['name' => 'Escritório', 'timezone' => self::TZ]);
        FilamentTimezone::set(self::TZ);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 07:00', self::TZ));
    }

    protected function employee(string $cpf = '529.982.247-25', string $name = 'Rui'): Employee
    {
        $schedule = Schedule::query()->where('name', 'office')->first() ?? Schedule::create(['name' => 'office'] + ScheduleService::presets()['office'] + ['anchor_date' => '2026-11-02']);
        $person = app(PeopleService::class)->add($this->company, ['name' => $name, 'phone' => '11977776666', 'document' => $cpf], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $this->office->id, 'schedule_id' => $schedule->id,
        ]], $this->owner, false)['person'];

        return Employee::query()->where('person_id', $person->id)->sole();
    }

    public function test_crc_and_lines(): void
    {
        // The check value of CRC-16/KERMIT, the algorithm of the layout.
        $this->assertSame('2189', AfdLine::crc('123456789'));
        $this->assertSame('5120220001234', AfdLine::inpi('BR 51 2022 000123-4'));

        $at = CarbonImmutable::parse('2026-11-03 08:00:41', self::TZ);
        $this->assertSame('2026-11-03T08:00:00-0300', AfdLine::dateTime($at));
        $this->assertSame(73, strlen(AfdLine::punch(5, $at, '52998224725', $at, AfdLine::COLLECTOR_APP, false)));
        $this->assertSame(331, mb_strlen(AfdLine::employer(1, $at, null, 'cnpj', '11.222.333/0001-81', 'Instaladora São João Ltda', 'Rua A, 10')));
        $this->assertSame(118, mb_strlen(AfdLine::employee(2, $at, 'I', '52998224725', 'João Antônio', null)));
        $this->assertSame(64, strlen(AfdLine::trailer([7 => 3])));
        // Accents count as one character in ISO-8859-1.
        $this->assertSame(331, strlen(AfdLine::latin1(AfdLine::employer(1, $at, null, 'cnpj', '11222333000181', 'Instaladora São João Ltda', 'Rua A'))));
    }

    public function test_employer_and_employee_records_share_the_numbering(): void
    {
        $settings = TimeClockSetting::current();
        $settings->forceFill(['employer_document_type' => 'cnpj', 'employer_document' => '11222333000181', 'employer_name' => 'Instaladora Ltda'])->save();

        $rui = $this->employee();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00', self::TZ));
        $punch = app(PunchService::class)->punch($rui, ['photo_path' => 'x.jpg']);

        // 1 employer, 2 employee included, 3 the punch.
        $this->assertSame([1 => AfdRecord::EMPLOYER, 2 => AfdRecord::EMPLOYEE], AfdRecord::query()->orderBy('nsr')->pluck('type', 'nsr')->all());
        $this->assertSame(3, $punch->nsr);
        $this->assertNull(app(PunchService::class)->brokenLink());

        $employer = AfdRecord::query()->where('type', AfdRecord::EMPLOYER)->sole()->line;
        $this->assertSame('0000000012', substr($employer, 0, 10));
        $this->assertSame('1'.'11222333000181', substr($employer, 48, 15));
        $this->assertSame(AfdLine::crc(substr($employer, 0, 327)), substr($employer, 327));

        $included = AfdRecord::query()->where('type', AfdRecord::EMPLOYEE)->sole()->line;
        $this->assertSame('I'.'052998224725'.'Rui', substr($included, 34, 16));

        // Saving the settings without changing the employer writes nothing new.
        $settings->refresh()->forceFill(['overtime_percent' => 60])->save();
        $this->assertSame(2, AfdRecord::count());

        // Name changed (A), then stopped (E).
        app(PeopleService::class)->updateDetails($rui->person, ['name' => 'Rui Lima', 'phone' => '11977776666', 'document' => '529.982.247-25'], true);
        app(PeopleService::class)->deactivate($this->company, $rui->person->fresh());
        $this->assertSame(['I', 'A', 'E'], AfdRecord::query()->where('type', AfdRecord::EMPLOYEE)->orderBy('nsr')->pluck('line')->map(fn (string $line): string => $line[34])->all());
        $this->assertStringContainsString('Rui Lima', AfdRecord::query()->where('type', AfdRecord::EMPLOYEE)->orderByDesc('nsr')->value('line'));

        // Records never change.
        $this->expectException(LogicException::class);
        AfdRecord::query()->first()->forceFill(['line' => 'x'])->save();
    }

    public function test_the_file_with_every_record_and_the_receipt(): void
    {
        TimeClockSetting::current()->forceFill(['employer_document_type' => 'cnpj', 'employer_document' => '11222333000181', 'employer_name' => 'Instaladora Ltda'])->save();
        $rui = $this->employee();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00', self::TZ));
        $first = app(PunchService::class)->punch($rui, ['photo_path' => 'x.jpg']);
        // Taken without signal at 12:00, arrived at 12:30.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:30', self::TZ));
        $second = app(PunchService::class)->punch($rui, ['photo_path' => 'x.jpg', 'taken_at' => CarbonImmutable::parse('2026-11-03 12:00', self::TZ)]);

        $this->assertSame(AfdLine::punchHash($first->afd_line, null), $first->afd_hash);
        $this->assertSame(AfdLine::punchHash($second->afd_line, $first->afd_hash), $second->afd_hash);
        $this->assertSame('1', $second->afd_line[72]);
        $this->assertSame('2026-11-03T12:00:00-0300', substr($second->afd_line, 10, 24));
        $this->assertSame('2026-11-03T12:30:00-0300', substr($second->afd_line, 46, 24));

        $day = CarbonImmutable::parse('2026-11-03');
        $lines = explode("\r\n", rtrim(app(AfdFile::class)->contents($day, $day), "\r\n"));
        // Header, employer, employee, 2 punches, trailer, signature (ordered by NSR).
        $this->assertCount(7, $lines);
        $this->assertSame(['1', '2', '5', '7', '7'], array_map(fn (string $line): string => $line[9], array_slice($lines, 0, 5)));
        $this->assertSame([302, 331, 118, 137, 137, 64, 100], array_map('strlen', $lines));
        $this->assertSame('999999999'.'000000001'.'000000000'.'000000000'.'000000001'.'000000000'.'000000002'.'9', $lines[5]);
        $this->assertSame($first->afd_line.$first->afd_hash, $lines[3]);
        $this->assertStringStartsWith('AFD11222333000181REP_P', app(AfdFile::class)->fileName($day, $day));

        // The receipt shows the SHA-256.
        $this->assertSame($second->afd_hash, app(ReceiptService::class)->data($second)['punch']->afd_hash);
        $this->get(app(ReceiptService::class)->url($second))->assertOk()->assertSee($second->afd_hash);
    }

    public function test_the_working_time_file(): void
    {
        TimeClockSetting::current()->forceFill(['employer_document_type' => 'cnpj', 'employer_document' => '11222333000181', 'employer_name' => 'Instaladora Ltda'])->save();
        $rui = $this->employee();
        foreach (['2026-11-03 08:00', '2026-11-03 08:02', '2026-11-03 12:00', '2026-11-03 13:00'] as $moment) {
            $this->travelTo(CarbonImmutable::parse($moment, self::TZ));
            app(PunchService::class)->punch($rui, ['photo_path' => 'x.jpg']);
        }
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', self::TZ));
        $double = Punch::query()->orderBy('nsr')->skip(1)->first();
        app(AdjustmentService::class)->record($rui, ['date' => '2026-11-03', 'type' => 'ignore_punch', 'punch_id' => $double->id, 'reason' => 'Bateu duas vezes'], $this->owner);
        app(AdjustmentService::class)->record($rui, ['date' => '2026-11-03', 'type' => 'add_punch', 'time' => '18:00', 'reason' => 'Esqueceu a saída'], $this->owner);

        $day = CarbonImmutable::parse('2026-11-01');
        $text = mb_convert_encoding(app(AejFile::class)->contents($day, CarbonImmutable::parse('2026-11-04')), 'UTF-8', 'ISO-8859-1');
        $lines = preg_split('/\R/', rtrim($text));

        $this->assertStringStartsWith('01|1|11222333000181|||Instaladora Ltda|2026-11-01|2026-11-04|', $lines[0]);
        $this->assertStringEndsWith('|002', $lines[0]);
        $this->assertContains('02|1|3|', $lines);
        $this->assertContains('03|1|52998224725|Rui', $lines);
        $this->assertContains('04|H01|540|0800|1200|1300|1800', $lines);
        // Nov 3: the punches in pairs, the forgotten exit added with the reason, the double punch left out.
        $this->assertContains('05|1|2026-11-03T08:00:00-0300|1|E|1|O|H01|', $lines);
        $this->assertContains('05|1|2026-11-03T12:00:00-0300|1|S|1|O||', $lines);
        $this->assertContains('05|1|2026-11-03T13:00:00-0300|1|E|2|O||', $lines);
        $this->assertContains('05|1|2026-11-03T18:00:00-0300||S|2|I||Esqueceu a saída', $lines);
        $this->assertContains('05|1|2026-11-03T08:02:00-0300|1|D|0|O||Bateu duas vezes', $lines);
        // Sunday the 1st is a rest day (the 2nd is a holiday); Wednesday the 4th has no punch: an unjustified absence.
        $this->assertContains('07|1|1|2026-11-01||', $lines);
        $this->assertContains('07|1|2|2026-11-04||', $lines);
        $this->assertStringStartsWith('08|Kiban Core Ponto|', $lines[count($lines) - 3]);
        $this->assertSame('99|1|1|1|1|5|0|2|1', $lines[count($lines) - 2]);
        $this->assertSame('ASSINATURA_DIGITAL_EM_ARQUIVO_P7S', rtrim(end($lines)));
    }

    public function test_outside_brazil_there_are_no_afd_records(): void
    {
        TimeClockSetting::current()->forceFill(['labor_profile' => LaborProfile::MexicoLft])->save();
        $ana = $this->employee();
        app(PunchService::class)->punch($ana, ['photo_path' => 'x.jpg']);

        $this->assertSame(0, AfdRecord::count());
        $this->assertSame(1, Punch::sole()->nsr);
    }
}
