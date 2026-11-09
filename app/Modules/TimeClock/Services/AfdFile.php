<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Afd\AfdLine;
use App\Modules\TimeClock\Models\AfdRecord;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Rules\Cpf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Builder;

/**
 * Brazil: the punches file (AFD, Portaria MTP 671/2021, REP-P, layout 004) of a period: header (type 1), the
 * employer (2) and employee (5) records and the punches (7, with the SHA-256 kept when each punch was made), in
 * the order of their numbers (NSR), the trailer (9) and the signature line.
 *
 * Still missing for the official delivery: the digital signature (.p7s, with Kiban's ICP-Brasil certificate)
 * and the INPI registration of the program; both wait for the company's CNPJ. Punches imported from another
 * clock (AfdImport) are not in this file: they are in the AFD of that clock, and in the AEJ.
 */
class AfdFile
{
    /**
     * What is missing for a complete file (shown to the manager before the download).
     *
     * @return array<int, string>
     */
    public function warnings(CarbonInterface $from, CarbonInterface $to): array
    {
        $settings = TimeClockSetting::current();
        $warnings = [];

        if (blank($settings->employer_document) || blank($settings->employer_name)) {
            $warnings[] = __('timeclock.afd.missing_employer');
        }

        if (blank(config('kiban.timeclock.inpi_registration'))) {
            $warnings[] = __('timeclock.afd.missing_inpi');
        }

        $withoutCpf = $this->punches($from, $to)->with('employee.person')->get()->pluck('employee')->unique('id')
            ->filter(fn ($employee): bool => ! Cpf::isValid((string) $employee?->person?->document))
            ->count();

        if ($withoutCpf > 0) {
            $warnings[] = trans_choice('timeclock.afd.missing_cpf', $withoutCpf, ['count' => $withoutCpf]);
        }

        return $warnings;
    }

    /** The file contents (ISO-8859-1, one record per line, CRLF). */
    public function contents(CarbonInterface $from, CarbonInterface $to): string
    {
        $settings = TimeClockSetting::current();
        $tz = FilamentTimezone::get();
        $counts = [];

        $lines = $this->punches($from, $to)->get()
            ->map(fn (Punch $punch): array => ['nsr' => $punch->nsr, 'type' => 7, 'line' => ($punch->afd_line ?? '').$punch->afd_hash])
            ->concat(AfdRecord::query()
                ->whereBetween('recorded_at', [$this->start($from, $tz)->utc(), $this->start($to, $tz)->addDay()->utc()])
                ->get()
                ->map(fn (AfdRecord $record): array => ['nsr' => $record->nsr, 'type' => $record->type, 'line' => $record->line]))
            ->sortBy('nsr')
            ->each(function (array $record) use (&$counts): void {
                $counts[$record['type']] = ($counts[$record['type']] ?? 0) + 1;
            })
            ->pluck('line')
            ->all();

        $all = [
            AfdLine::header((string) $settings->employer_document_type, (string) $settings->employer_document, (string) $settings->employer_name,
                (string) config('kiban.timeclock.inpi_registration'), $from, $to, CarbonImmutable::now($tz), (string) config('kiban.timeclock.developer_document')),
            ...$lines,
            AfdLine::trailer($counts),
            AfdLine::signature(),
        ];

        return AfdLine::latin1(implode("\r\n", $all)."\r\n");
    }

    /** "AFD" + INPI number + employer CNPJ/CPF + "REP_P" (official name). */
    public function fileName(CarbonInterface $from, CarbonInterface $to): string
    {
        $settings = TimeClockSetting::current();

        return 'AFD'.AfdLine::inpi((string) config('kiban.timeclock.inpi_registration')).AfdLine::document((string) $settings->employer_document)
            .'REP_P_'.$from->format('Ymd').'-'.$to->format('Ymd').'.txt';
    }

    /**
     * The company's own punches whose local date is in the period (imported ones are in the other clock's AFD).
     *
     * @return Builder<Punch>
     */
    protected function punches(CarbonInterface $from, CarbonInterface $to): Builder
    {
        // The local date of the punch is in the official line itself (positions 11 to 20).
        return Punch::query()->whereNotNull('afd_line')
            ->whereBetween('punched_at', [CarbonImmutable::parse($from->toDateString())->subDay()->utc(), CarbonImmutable::parse($to->toDateString())->addDays(2)->utc()])
            ->whereRaw('substr(afd_line, 11, 10) between ? and ?', [$from->toDateString(), $to->toDateString()]);
    }

    protected function start(CarbonInterface $date, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString(), $tz);
    }
}
