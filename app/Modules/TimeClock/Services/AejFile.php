<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Afd\AfdLine;
use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Clock;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\TimeClockSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;

/**
 * Brazil: the working-time file (AEJ, Arquivo Eletrônico de Jornada, Portaria 671, layout 002) of a period: the
 * calculated journeys, as the time sheet shows them. Fields separated by "|", ISO-8859-1, CRLF:
 * 01 header · 02 the clocks used (this program, REP-P, and each clock whose AFD was imported) · 03 each employee ·
 * 04 the contract schedules · 05 the punches (E/S in pairs; O original, I added by a correction with the reason,
 * D left out with the reason) · 07 rest days, absences and hour-bank movements · 08 the program · 99 the totals.
 * The digital signature (.p7s) waits for the certificate, like the AFD.
 */
class AejFile
{
    public const VERSION = '002';

    public function __construct(protected DayCalculator $days, protected MonthClosing $closing, protected HourBank $bank) {}

    public function contents(CarbonInterface $from, CarbonInterface $to): string
    {
        $settings = TimeClockSetting::current();
        $tz = FilamentTimezone::get();
        $repIds = [];
        $schedules = [];
        $employees = [];
        $punches = [];
        $absences = [];
        $bankOn = $this->bank->enabled();

        foreach ($this->employees($from, $to) as $index => $employee) {
            $link = $index + 1;
            $employees[] = ['03', $link, AfdLine::cpfDigits($employee->person?->document), $this->text((string) $employee->person?->name, 150)];
            $days = $this->days->period($employee, $from, $to);
            $ids = collect($days)->flatMap(fn (array $day): array => [...$day['ignored'], ...collect($day['punches'])->where('kind', 'real')->pluck('id')->all()]);
            $original = Punch::query()->whereIn('id', $ids->all())->get()->keyBy('id');

            foreach ($days as $day) {
                if (! $day['employed'] || $day['moment'] === 'future') {
                    continue;
                }

                /** @var CarbonImmutable $date */
                $date = $day['date'];
                $code = $day['plan'] !== null ? $this->scheduleCode($day['plan'], $schedules) : '';
                $adjustments = collect($day['adjustments'])->keyBy('id');

                foreach (array_values($day['punches']) as $position => $punch) {
                    $added = $punch['kind'] === 'added';
                    $rep = $added ? '' : $this->repId($original->get($punch['id']), $repIds);

                    $punches[] = [
                        '05', $link, AfdLine::dateTime($punch['at']), $rep, $position % 2 === 0 ? 'E' : 'S', intdiv($position, 2) + 1,
                        $added ? 'I' : 'O',
                        $position === 0 ? $code : '',
                        $added ? $this->text((string) $adjustments->get($punch['id'])?->reason, 150) : '',
                    ];
                }

                foreach (collect($day['adjustments'])->where('type', Adjustment::IGNORE_PUNCH) as $ignore) {
                    if (($punch = $original->get($ignore->punch_id)) !== null) {
                        $punches[] = ['05', $link, AfdLine::dateTime($punch->localTime()), $this->repId($punch, $repIds), 'D', 0, 'O', '', $this->text((string) $ignore->reason, 150)];
                    }
                }

                if ($day['type'] === 'off') {
                    $absences[] = ['07', $link, 1, $date->toDateString(), '', ''];
                }

                if ($day['absence']) {
                    $absences[] = ['07', $link, 2, $date->toDateString(), '', ''];
                }

                if ($bankOn && $day['type'] === 'work' && ! $day['absence'] && ($day['extra'] > 0 || $day['deficit'] > 0)) {
                    $absences[] = $day['extra'] > 0
                        ? ['07', $link, 3, $date->toDateString(), $day['extra'], 1]
                        : ['07', $link, 3, $date->toDateString(), $day['deficit'], 2];
                }
            }
        }

        // 1 is this program (REP-P); then each clock whose punches were imported, with its type and number.
        $reps = [['02', 1, 3, AfdLine::inpi((string) config('kiban.timeclock.inpi_registration'))]];
        foreach (Clock::query()->whereIn('id', array_keys($repIds))->get() as $clock) {
            $reps[] = ['02', $repIds[$clock->getKey()], $clock->type, (string) preg_replace('/[^0-9A-Za-z]/', '', $clock->number)];
        }
        $developer = AfdLine::document((string) config('kiban.timeclock.developer_document'));

        $records = [
            ['01', $settings->employer_document_type === 'cpf' ? 2 : 1, AfdLine::document((string) $settings->employer_document), '', '',
                $this->text((string) $settings->employer_name, 150), $from->format('Y-m-d'), $to->format('Y-m-d'), AfdLine::dateTime(CarbonImmutable::now($tz)), self::VERSION],
            ...$reps,
            ...$employees,
            ...array_values($schedules),
            ...$punches,
            ...$absences,
            ['08', $this->text((string) config('kiban.timeclock.program_name'), 150), (string) config('kiban.timeclock.program_version'),
                strlen($developer) === 11 ? 2 : 1, $developer, $this->text((string) config('kiban.timeclock.developer_name'), 150), mb_substr((string) config('kiban.timeclock.developer_email'), 0, 50)],
        ];

        $counts = collect($records)->countBy(fn (array $record): string => $record[0]);
        $records[] = ['99', ...array_map(fn (string $type): int => (int) ($counts[$type] ?? 0), ['01', '02', '03', '04', '05', '06', '07', '08'])];

        $lines = array_map(fn (array $record): string => implode('|', $record), $records);
        $lines[] = AfdLine::signature();

        return AfdLine::latin1(implode("\r\n", $lines)."\r\n");
    }

    public function fileName(CarbonInterface $from, CarbonInterface $to): string
    {
        return 'AEJ_'.AfdLine::document((string) TimeClockSetting::current()->employer_document).'_'.$from->format('Ymd').'_'.$to->format('Ymd').'.txt';
    }

    /**
     * @return iterable<int, Employee>
     */
    protected function employees(CarbonInterface $from, CarbonInterface $to): iterable
    {
        return $this->closing->employeesOf($from)
            ->filter(fn (Employee $employee): bool => $employee->admitted_on->toDateString() <= $to->toDateString())
            ->values();
    }

    /**
     * One 04 record per different daily schedule: daily minutes, then the entry and exit times (with the break).
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<int, mixed>>  $schedules
     */
    protected function scheduleCode(array $plan, array &$schedules): string
    {
        $times = array_values(array_filter([$plan['start'], $plan['break_start'] ?? null, $plan['break_end'] ?? null, $plan['end']]));
        $key = $plan['minutes'].'|'.implode('|', $times);

        if (! isset($schedules[$key])) {
            $schedules[$key] = ['04', 'H'.str_pad((string) (count($schedules) + 1), 2, '0', STR_PAD_LEFT), (int) $plan['minutes'],
                ...array_map(fn (string $time): string => str_replace(':', '', substr($time, 0, 5)), $times)];
        }

        return $schedules[$key][1];
    }

    /**
     * Which clock the punch came from: 1 is this program; an imported clock gets its own number.
     *
     * @param  array<int, int>  $repIds
     */
    protected function repId(?Punch $punch, array &$repIds): string
    {
        if ($punch?->clock_id === null) {
            return '1';
        }

        return (string) ($repIds[$punch->clock_id] ??= count($repIds) + 2);
    }

    /** Free text without the separator and line breaks. */
    protected function text(string $value, int $length): string
    {
        return mb_substr(trim(str_replace(['|', "\r", "\n", "\t"], ' ', $value)), 0, $length);
    }
}
