<?php

namespace App\Modules\TimeClock\Afd;

use App\Modules\TimeClock\Models\Clock;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Rules\Cpf;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Brazil: the punches of a time clock the company already has (card, fingerprint, face; any brand), from its AFD
 * file. Every certified clock writes the same official file, so there is no integration per brand:
 * - Portaria 671 (2021 on): punch records type 3 (REP-C/REP-A) and 7 (REP-P), employee by CPF, time with its zone;
 * - Portaria 1510 (older clocks, still in use): type 3 with date, time and the employee's PIS.
 * The clock is recognized by the number in the file header (created on the first import). A punch already
 * imported (same clock and number), of someone not registered here, or in a closed month is left out and counted.
 */
class AfdImport
{
    public function __construct(protected MonthClosing $closing) {}

    /**
     * @return array{clock: Clock, imported: int, duplicates: int, closed: int, unknown: array<int, string>}
     */
    public function import(string $contents, ?string $name = null): array
    {
        $lines = $this->lines($contents);
        $header = collect($lines)->first(fn (string $line): bool => strlen($line) > 200 && substr($line, 0, 10) === '0000000001');

        if ($header === null) {
            throw new AfdImportException(__('timeclock.import.not_afd'));
        }

        // Portaria 671: header of 302 (or 298 without CRC in old files) characters; 1510: 232.
        $modern = strlen($header) >= 298;
        $number = trim(substr($header, $modern ? 189 : 187, 17));
        $records = $this->records($lines, $modern);

        $clock = Clock::query()->firstOrNew(['number' => $number !== '' ? $number : 'sem-numero']);
        if (! $clock->exists) {
            $clock->fill([
                'name' => filled($name) ? $name : __('timeclock.import.clock_name', ['number' => $number]),
                'type' => collect($records)->contains('type', 7) ? Clock::REP_P : Clock::REP_C,
            ])->save();
        }

        $employees = Employee::query()->with(['person', 'workplace'])->get();
        $byCpf = $employees->filter(fn (Employee $employee): bool => Cpf::isValid((string) $employee->person?->document))
            ->keyBy(fn (Employee $employee): string => (string) Cpf::digits((string) $employee->person->document));
        $byPis = $employees->filter(fn (Employee $employee): bool => filled($employee->pis))
            ->keyBy(fn (Employee $employee): string => ltrim((string) preg_replace('/\D/', '', (string) $employee->pis), '0'));
        $existing = Punch::query()->where('clock_id', $clock->getKey())->pluck('clock_nsr')->flip();

        $result = ['clock' => $clock, 'imported' => 0, 'duplicates' => 0, 'closed' => 0, 'unknown' => []];

        DB::transaction(function () use ($records, $clock, $byCpf, $byPis, $existing, &$result): void {
            foreach ($records as $record) {
                if ($existing->has($record['nsr'])) {
                    $result['duplicates']++;

                    continue;
                }

                $employee = $record['cpf'] !== null ? $byCpf->get($record['cpf']) : $byPis->get(ltrim((string) $record['pis'], '0'));

                if ($employee === null) {
                    $result['unknown'][] = $record['cpf'] !== null ? 'CPF '.Cpf::format($record['cpf']) : 'PIS '.$record['pis'];

                    continue;
                }

                $timezone = $employee->workplace?->timezone ?? FilamentTimezone::get();
                $at = $record['at'] ?? CarbonImmutable::createFromFormat('dmYHi|', $record['local'], $timezone);

                if ($this->closing->isClosed($employee, CarbonImmutable::parse($at->setTimezone($timezone)->toDateString()))) {
                    $result['closed']++;

                    continue;
                }

                $punch = new Punch;
                $punch->forceFill([
                    'employee_id' => $employee->getKey(),
                    'punched_at' => $at->utc(),
                    'timezone' => $timezone,
                    'source' => Punch::CLOCK,
                    'clock_id' => $clock->getKey(),
                    'clock_nsr' => $record['nsr'],
                    'flags' => [],
                ])->save();

                $existing->put($record['nsr'], true);
                $result['imported']++;
            }

            $clock->forceFill([
                'last_nsr' => max($clock->last_nsr, (int) collect($records)->max('nsr')),
                'last_import_at' => now(),
            ])->save();
        });

        $result['unknown'] = array_values(array_unique($result['unknown']));

        return $result;
    }

    /**
     * @return array<int, string>
     */
    protected function lines(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
        }

        return array_values(array_filter(array_map('rtrim', preg_split('/\r\n|\r|\n/', $contents) ?: []), fn (string $line): bool => $line !== ''));
    }

    /**
     * The punch records: number (NSR), moment (with its zone) or local date-time, and CPF or PIS.
     *
     * @param  array<int, string>  $lines
     * @return array<int, array{nsr: int, type: int, at: ?CarbonImmutable, local: ?string, cpf: ?string, pis: ?string}>
     */
    protected function records(array $lines, bool $modern): array
    {
        $records = [];

        foreach ($lines as $line) {
            $type = $line[9] ?? '';
            $length = strlen($line);

            try {
                if ($modern && $type === '3' && $length >= 46 && $length <= 50) {
                    $records[] = ['nsr' => (int) substr($line, 0, 9), 'type' => 3, 'at' => $this->dateTime(substr($line, 10, 24)), 'local' => null, 'cpf' => $this->cpf(substr($line, 34, 12)), 'pis' => null];
                } elseif ($modern && $type === '7' && $length >= 73) {
                    $records[] = ['nsr' => (int) substr($line, 0, 9), 'type' => 7, 'at' => $this->dateTime(substr($line, 10, 24)), 'local' => null, 'cpf' => $this->cpf(substr($line, 34, 12)), 'pis' => null];
                } elseif (! $modern && $type === '3' && $length === 34) {
                    $records[] = ['nsr' => (int) substr($line, 0, 9), 'type' => 3, 'at' => null, 'local' => substr($line, 10, 12), 'cpf' => null, 'pis' => substr($line, 22, 12)];
                }
            } catch (Throwable) {
                // A damaged line is left out; the others still count.
            }
        }

        return $records;
    }

    protected function dateTime(string $value): CarbonImmutable
    {
        $at = CarbonImmutable::createFromFormat('Y-m-d\TH:i:sO', $value);

        if ($at === null) {
            throw new AfdImportException('date');
        }

        return $at;
    }

    protected function cpf(string $value): string
    {
        return substr(str_pad((string) preg_replace('/\D/', '', $value), 11, '0', STR_PAD_LEFT), -11);
    }
}
