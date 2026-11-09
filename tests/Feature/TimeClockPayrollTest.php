<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Filament\ClosingsPage;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Payroll\PayrollExport;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\Modules\TimeClock\Services\TimesheetDocument;
use App\People\PeopleService;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The file for the accountant's payroll program: the codes of each event saved once, the employee by the
 * "Matrícula", and the import layouts of Domínio, Alterdata, Fortes, Questor, Senior, RM Labore and the free CSV.
 */
class TimeClockPayrollTest extends TestCase
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
        $office = Workplace::create(['name' => 'Escritório', 'timezone' => self::TZ]);
        $schedule = Schedule::create(['name' => 'office'] + ScheduleService::presets()['office'] + ['anchor_date' => '2026-11-02']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', self::TZ));
        $person = app(PeopleService::class)->add($this->company, ['name' => 'Rui', 'phone' => '11977776666', 'document' => '529.982.247-25'], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-11-01', 'workplace_id' => $office->id, 'schedule_id' => $schedule->id, 'registration' => '88', 'pis' => '120.54312.78-1',
        ]], $this->owner, false)['person'];
        $this->rui = Employee::query()->where('person_id', $person->id)->sole();
        TimeClockSetting::current()->forceFill(['employer_document_type' => 'cnpj', 'employer_document' => '11222333000181', 'employer_name' => 'Instaladora Ltda'])->save();

        // Nov 3: 2 hours of overtime (until 20:00); the other working days without punches are absences.
        foreach (['2026-11-03 08:00', '2026-11-03 12:00', '2026-11-03 13:00', '2026-11-03 20:00'] as $moment) {
            $this->travelTo(CarbonImmutable::parse($moment, self::TZ));
            app(PunchService::class)->punch($this->rui, ['photo_path' => 'x.jpg']);
        }
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00', self::TZ));
    }

    /** @param  array<string, mixed>  $extra */
    protected function configure(string $system, array $extra = []): void
    {
        app(PayrollExport::class)->save(['system' => $system, 'company_code' => '11', 'hours' => PayrollExport::DEFAULT_HOURS[$system],
            'codes' => ['extra' => '150', 'absences' => '8069', 'night' => ''], 'senior_codcal' => '404', 'senior_tabeve' => '941'] + $extra);
    }

    /** @return array<int, string> */
    protected function lines(): array
    {
        $text = mb_convert_encoding(app(PayrollExport::class)->contents(CarbonImmutable::parse('2026-11-01')), 'UTF-8', 'Windows-1252');

        return preg_split('/\R/', rtrim($text));
    }

    public function test_domínio_with_the_absence_dates(): void
    {
        $this->configure('dominio');
        $totals = app(TimesheetDocument::class)->month($this->rui, CarbonImmutable::parse('2026-11-01'))['totals'];
        $this->assertSame(120, $totals['extra']);
        $absences = (int) $totals['absences'];
        $this->assertGreaterThan(10, $absences);

        $lines = $this->lines();
        // Overtime: employee 88, 11/2026, rubric 150, monthly process, 2,00 hours, company 11.
        $this->assertSame('10'.'0000000088'.'202611'.'000000150'.'11'.'000000200'.'0000000011', $lines[0]);
        // Absences in days, followed by one record 11 per date.
        $this->assertSame('10'.'0000000088'.'202611'.'000008069'.'11'.str_pad((string) ($absences * 100), 9, '0', STR_PAD_LEFT).'0000000011', $lines[1]);
        $this->assertCount(2 + $absences, $lines);
        $this->assertSame('11'.'20261104'.'1', $lines[2]);
        // The night event has no code: not in the file.
        $this->assertSame([48, 48], [strlen($lines[0]), strlen($lines[1])]);
    }

    public function test_every_layout_has_its_size(): void
    {
        $this->configure('alterdata', ['alterdata_process' => 'F']);
        $lines = $this->lines();
        $this->assertSame(86, strlen($lines[0]));
        $this->assertSame('000001'.'00011'.'011126'.'301126'.'000000000000'.'00'.'150'.'00000000000120'.'000088'.'F'.'11222333000181'.'12054312781', $lines[0]);

        $this->configure('senior');
        $this->assertSame('01'.'0011'.'1'.'000000088'.'00404'.'941'.'0150'.'000000000'.'01'.'00000000200'.'00000000000'.'I', $this->lines()[0]);

        $this->configure('rm');
        $line = $this->lines()[0];
        $this->assertSame(81, strlen($line));
        $this->assertSame('0000000000000088'.'05122026'.'0150'.'002:00'.'000000000002.00'.'000000000000.00'.'000000000000.00'.'NN', $line);

        $this->configure('fortes');
        $lines = $this->lines();
        $this->assertSame(['01TC0011', '000088;150;2,00;0'], array_slice($lines, 0, 2));
        $this->assertStringStartsWith('Z;2;', end($lines));

        $this->configure('questor');
        $this->assertSame(['Empregado;Nome;150H;8069R', '88;Rui;120;'], [$this->lines()[0], substr($this->lines()[1], 0, 11)]);

        $this->configure('generic');
        $lines = $this->lines();
        $this->assertStringStartsWith('88;52998224725;12054312781;Rui;'.__('timeclock.payroll.events.extra').';150;2,00;', $lines[1]);
    }

    public function test_the_codes_are_saved_from_the_closing_screen(): void
    {
        // After the date jump: a fresh license (it lasts 72 hours) and a new sign-in.
        $this->company->forceFill(['license_token' => $this->licenseToken($this->company, ['ponto' => []]), 'license_fetched_at' => now(), 'license_expires_at' => now()->addHours(72)])->save();
        $this->signIn($this->company->fresh());

        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])
            ->assertActionVisible('payroll')
            ->callAction('payroll', ['system' => 'dominio', 'company_code' => '7', 'hours' => 'hhmm', 'codes' => ['extra' => '150', 'late' => '8070']])
            ->assertFileDownloaded('folha-dominio-2026-11.txt');

        $config = app(PayrollExport::class)->config();
        $this->assertSame(['dominio', '7', 'hhmm', '150', '8070', ''], [$config['system'], $config['company_code'], $config['hours'], $config['codes']['extra'], $config['codes']['late'], $config['codes']['night']]);
        $this->assertSame([], array_filter(app(PayrollExport::class)->warnings(CarbonImmutable::parse('2026-11-01'))));
    }
}
