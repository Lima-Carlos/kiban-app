<?php

namespace App\Modules\TimeClock\Payroll;

use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Modules\TimeClock\Services\TimesheetDocument;
use App\Rules\Cpf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The month for the payroll program of the accountant: one line per employee and event (overtime, rest-day
 * overtime, night hours, absences in days, late arrivals/early departures in hours, expired hour bank), in the
 * import layout of the chosen program. Each accountant has their own codes, so the company fills in once the
 * code of each event (saved in tc_settings.payroll); an event without a code is not exported. The employee is
 * identified by the "Matrícula" (the employee's code in the payroll program).
 *
 * Layouts in docs/planejamento/leiautes-folha.md. Domínio and RM Labore follow official layouts with examples;
 * the others still need to be checked with an accountant who uses them (marked as "check" on the screen).
 */
class PayrollExport
{
    public const SYSTEMS = ['dominio', 'alterdata', 'fortes', 'questor', 'senior', 'rm', 'generic'];

    /** Layouts confirmed by official examples; the others say "confirm with the accountant". */
    public const CONFIRMED = ['dominio', 'rm', 'generic'];

    public const EVENTS = ['extra', 'extra_rest', 'night', 'absences', 'late', 'bank_expired'];

    /** Events counted in days (the others in hours). */
    public const DAY_EVENTS = ['absences'];

    /** decimal: 1h30 = 1,50 · hhmm: 1h30 = 1,30 · minutes: 90. */
    public const HOUR_FORMATS = ['decimal', 'hhmm', 'minutes'];

    public const DEFAULT_HOURS = ['dominio' => 'decimal', 'alterdata' => 'minutes', 'fortes' => 'decimal', 'questor' => 'minutes', 'senior' => 'hhmm', 'rm' => 'hhmm', 'generic' => 'decimal'];

    public function __construct(protected MonthClosing $closing, protected TimesheetDocument $document) {}

    /**
     * The saved configuration with the defaults.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $saved = (array) (TimeClockSetting::current()->payroll ?? []);
        $system = in_array($saved['system'] ?? null, self::SYSTEMS, true) ? $saved['system'] : 'dominio';

        return [
            'system' => $system,
            'company_code' => (string) ($saved['company_code'] ?? ''),
            'hours' => in_array($saved['hours'] ?? null, self::HOUR_FORMATS, true) ? $saved['hours'] : self::DEFAULT_HOURS[$system],
            'codes' => array_merge(array_fill_keys(self::EVENTS, ''), array_map('strval', (array) ($saved['codes'] ?? []))),
            'senior_codcal' => (string) ($saved['senior_codcal'] ?? ''),
            'senior_tabeve' => (string) ($saved['senior_tabeve'] ?? ''),
            'rm_pay_day' => (int) ($saved['rm_pay_day'] ?? 5),
            'alterdata_process' => (string) ($saved['alterdata_process'] ?? ''),
        ];
    }

    /** @param  array<string, mixed>  $config */
    public function save(array $config): void
    {
        $settings = TimeClockSetting::current();
        $settings->forceFill(['payroll' => [
            'system' => $config['system'],
            'company_code' => trim((string) ($config['company_code'] ?? '')),
            'hours' => $config['hours'] ?? self::DEFAULT_HOURS[$config['system']],
            'codes' => array_map(fn ($code): string => trim((string) $code), array_intersect_key((array) ($config['codes'] ?? []), array_flip(self::EVENTS))),
            'senior_codcal' => trim((string) ($config['senior_codcal'] ?? '')),
            'senior_tabeve' => trim((string) ($config['senior_tabeve'] ?? '')),
            'rm_pay_day' => max(1, min(28, (int) ($config['rm_pay_day'] ?? 5))),
            'alterdata_process' => trim((string) ($config['alterdata_process'] ?? '')),
        ]])->save();
    }

    /**
     * What is missing (shown before the download).
     *
     * @return array<int, string>
     */
    public function warnings(CarbonInterface $month): array
    {
        $config = $this->config();
        $warnings = [];

        if (array_filter($config['codes']) === []) {
            $warnings[] = __('timeclock.payroll.missing_codes');
        }

        if ($config['company_code'] === '' && in_array($config['system'], ['dominio', 'alterdata', 'fortes', 'senior'], true)) {
            $warnings[] = __('timeclock.payroll.missing_company');
        }

        $without = $this->closing->employeesOf($month)->filter(fn (Employee $employee): bool => blank($employee->registration))
            ->map(fn (Employee $employee): string => (string) $employee->person?->name);

        if ($without->isNotEmpty()) {
            $warnings[] = __('timeclock.payroll.missing_registration', ['names' => $without->implode(', ')]);
        }

        if (! in_array($config['system'], self::CONFIRMED, true)) {
            $warnings[] = __('timeclock.payroll.check_layout');
        }

        return $warnings;
    }

    /**
     * Every event of every employee in the month (quantity in minutes, or days for the day events).
     *
     * @return array<int, array{employee: Employee, event: string, code: string, quantity: int, dates: array<int, string>}>
     */
    public function rows(CarbonInterface $month): array
    {
        $config = $this->config();
        $start = CarbonImmutable::parse($month->format('Y-m-01'));
        $rows = [];

        foreach ($this->closing->employeesOf($start) as $employee) {
            if (blank($employee->registration)) {
                continue;
            }

            $data = $this->document->month($employee, $start);
            $totals = $data['totals'];
            $absenceDates = collect($data['days'])->filter(fn (array $day): bool => (bool) ($day['absence'] ?? false))
                ->map(fn (array $day): string => CarbonImmutable::parse((string) $day['date'])->toDateString())->values()->all();

            $quantities = [
                'extra' => (int) ($totals['extra'] ?? 0),
                'extra_rest' => (int) ($totals['extra_rest'] ?? 0),
                'night' => (int) ($totals['night_reduced'] ?? 0),
                'absences' => (int) ($totals['absences'] ?? 0),
                'late' => max(0, (int) ($totals['deficit'] ?? 0) - (int) ($totals['absence_minutes'] ?? 0)),
                'bank_expired' => (int) ($data['bank']['expired'] ?? 0),
            ];

            foreach (self::EVENTS as $event) {
                if ($config['codes'][$event] === '' || $quantities[$event] <= 0) {
                    continue;
                }

                $rows[] = ['employee' => $employee, 'event' => $event, 'code' => $config['codes'][$event], 'quantity' => $quantities[$event],
                    'dates' => $event === 'absences' ? $absenceDates : []];
            }
        }

        return $rows;
    }

    public function contents(CarbonInterface $month): string
    {
        $config = $this->config();
        $start = CarbonImmutable::parse($month->format('Y-m-01'));
        $rows = $this->rows($start);

        $lines = match ($config['system']) {
            'dominio' => $this->dominio($rows, $start, $config),
            'alterdata' => $this->alterdata($rows, $start, $config),
            'fortes' => $this->fortes($rows, $config),
            'questor' => $this->questor($rows, $config),
            'senior' => $this->senior($rows, $config),
            'rm' => $this->rm($rows, $start, $config),
            default => $this->generic($rows, $config),
        };

        // Windows programs: CRLF on every line (the last one too) and no accents outside the Windows table.
        return mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'Windows-1252', 'UTF-8');
    }

    public function fileName(CarbonInterface $month): string
    {
        $system = $this->config()['system'];

        return match ($system) {
            'alterdata' => 'MOV.DP',
            default => 'folha-'.$system.'-'.$month->format('Y-m').(in_array($system, ['fortes', 'questor', 'generic'], true) ? '.csv' : '.txt'),
        };
    }

    /**
     * Domínio Folha, "Importação de lançamentos": record 10 (48 positions), and for absences the dates (record 11).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function dominio(array $rows, CarbonImmutable $start, array $config): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = '10'.$this->num($row['employee']->registration, 10).$start->format('Ym').$this->num($row['code'], 9).'11'
                .$this->num((string) $this->amount($row, $config['hours']), 9).$this->num($config['company_code'], 10);

            foreach ($row['dates'] as $date) {
                $lines[] = '11'.str_replace('-', '', $date).'1';
            }
        }

        return $lines;
    }

    /**
     * Alterdata DP, "Lançamento por funcionário" (MOV.DP, 86 positions).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function alterdata(array $rows, CarbonImmutable $start, array $config): array
    {
        $cnpj = (string) preg_replace('/\D/', '', (string) TimeClockSetting::current()->employer_document);
        $lines = [];

        foreach (array_values($rows) as $index => $row) {
            $lines[] = $this->num((string) ($index + 1), 6).$this->num($config['company_code'], 5).$start->format('dmy').$start->endOfMonth()->format('dmy')
                .'000000'.'000000'.'00'.$this->num($row['code'], 3).$this->num((string) $this->amount($row, $config['hours']), 14)
                .$this->num($row['employee']->registration, 6).str_pad(mb_substr($config['alterdata_process'], 0, 1), 1)
                .$this->num($cnpj, 14).$this->num((string) $row['employee']->pis, 11);
        }

        return $lines;
    }

    /**
     * Fortes Pessoal, "Eventos da folha" (CSV): header 0·1·TC·company, employee;event;reference;value, footer Z.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function fortes(array $rows, array $config): array
    {
        $lines = ['01TC'.$this->num($config['company_code'], 4)];
        $total = 0;

        foreach ($rows as $row) {
            $amount = $this->amount($row, $config['hours']);
            $total += $amount;
            $lines[] = implode(';', [$this->num($row['employee']->registration, 6), $this->num($row['code'], 3), $this->decimal($amount, $row, $config['hours']), '0']);
        }

        $lines[] = implode(';', ['Z', count($rows), $config['hours'] === 'minutes' ? (string) $total : number_format($total / 100, 2, ',', ''), '0']);

        return $lines;
    }

    /**
     * Questor, "Eventos variáveis – importação planilha": one line per employee, one column per event (code + H for
     * hours or R for reference/days), hours in minutes.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function questor(array $rows, array $config): array
    {
        $columns = collect($rows)->map(fn (array $row): string => $row['code'].(in_array($row['event'], self::DAY_EVENTS, true) ? 'R' : 'H'))->unique()->values();
        $lines = [implode(';', ['Empregado', 'Nome', ...$columns->all()])];

        foreach (collect($rows)->groupBy(fn (array $row): int => $row['employee']->getKey()) as $employeeRows) {
            $employee = $employeeRows->first()['employee'];
            $values = $columns->mapWithKeys(fn (string $column): array => [$column => '0'])->all();

            foreach ($employeeRows as $row) {
                $column = $row['code'].(in_array($row['event'], self::DAY_EVENTS, true) ? 'R' : 'H');
                $values[$column] = $this->decimal($this->amount($row, 'minutes'), $row, 'minutes');
            }

            $lines[] = implode(';', [$employee->registration, Str::ascii((string) $employee->person?->name), ...array_values($values)]);
        }

        return $lines;
    }

    /**
     * Senior Rubi/Vetorh, model 35 "Lançamentos" (62 positions): hours in HHMM, values with 2 implied decimals.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function senior(array $rows, array $config): array
    {
        return array_map(fn (array $row): string => '01'.$this->num($config['company_code'], 4).'1'.$this->num($row['employee']->registration, 9)
            .$this->num($config['senior_codcal'], 5).$this->num($config['senior_tabeve'], 3).$this->num($row['code'], 4).'000000000'.'01'
            .$this->num((string) $this->amount($row, $config['hours']), 11).'00000000000'.'I', $rows);
    }

    /**
     * TOTVS RM Labore, "Importação do movimento" (81 positions): chapa, payment date, event, hours HHH:MM, reference.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function rm(array $rows, CarbonImmutable $start, array $config): array
    {
        $pay = $start->addMonth()->setDay($config['rm_pay_day'])->format('dmY');

        return array_map(function (array $row) use ($pay): string {
            $days = in_array($row['event'], self::DAY_EVENTS, true);
            $hours = $days ? '000:00' : str_pad((string) intdiv($row['quantity'], 60), 3, '0', STR_PAD_LEFT).':'.str_pad((string) ($row['quantity'] % 60), 2, '0', STR_PAD_LEFT);
            $reference = $days ? $row['quantity'] * 100 : intdiv($row['quantity'] * 100 + 30, 60);

            return $this->num($row['employee']->registration, 16).$pay.str_pad(mb_substr($row['code'], 0, 4), 4, '0', STR_PAD_LEFT).$hours
                .$this->real($reference).$this->real(0).$this->real(0).'N'.'N';
        }, $rows);
    }

    /**
     * Free layout (CSV, ";"): for any other program, or to type in by hand.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function generic(array $rows, array $config): array
    {
        $lines = [implode(';', [__('timeclock.fields.registration'), 'CPF', 'PIS', __('timeclock.fields.name'), __('timeclock.payroll.event'), __('timeclock.payroll.code'), __('timeclock.payroll.quantity'), __('timeclock.payroll.unit')])];

        foreach ($rows as $row) {
            $days = in_array($row['event'], self::DAY_EVENTS, true);
            $lines[] = implode(';', [
                $row['employee']->registration, Cpf::digits((string) $row['employee']->person?->document) ?? '', (string) $row['employee']->pis,
                (string) $row['employee']->person?->name, __('timeclock.payroll.events.'.$row['event']), $row['code'],
                $this->decimal($this->amount($row, $config['hours']), $row, $config['hours']),
                $days ? __('timeclock.payroll.units.days') : __('timeclock.payroll.units.'.$config['hours']),
            ]);
        }

        return $lines;
    }

    /**
     * The quantity as an integer with 2 implied decimals (days ×100; hours by the format), or minutes.
     *
     * @param  array<string, mixed>  $row
     */
    public function amount(array $row, string $hours): int
    {
        $quantity = (int) $row['quantity'];

        if (in_array($row['event'], self::DAY_EVENTS, true)) {
            return $quantity * 100;
        }

        return match ($hours) {
            'minutes' => $quantity,
            'hhmm' => intdiv($quantity, 60) * 100 + $quantity % 60,
            default => intdiv($quantity * 100 + 30, 60),
        };
    }

    /** "1,50" from 150 (or "90" in minutes). */
    protected function decimal(int $amount, array $row, string $hours): string
    {
        if ($hours === 'minutes' && ! in_array($row['event'], self::DAY_EVENTS, true)) {
            return (string) $amount;
        }

        return number_format($amount / 100, 2, ',', '');
    }

    /** "000000000010.50" (15 characters). */
    protected function real(int $hundredths): string
    {
        return str_pad(number_format($hundredths / 100, 2, '.', ''), 15, '0', STR_PAD_LEFT);
    }

    /** Only digits, zero-padded on the left. */
    protected function num(?string $value, int $length): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);

        return str_pad(substr($digits, -$length), $length, '0', STR_PAD_LEFT);
    }
}
