<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\People\Person;
use App\Rules\Cpf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Builder;

/**
 * Brazil: the punches file (AFD, Portaria MTP 671/2021, REP-P) of a period.
 *
 * PREVIEW, still to be validated against the official layout and the validator of the Ministry: it follows the
 * header (type 1), the punch records of a REP-P (type 7, with the SHA-256 chain) and the trailer (type 9).
 * Not included yet: the records of employer and employee changes (types 2 and 5) and the digital signature
 * (.p7s, needs the company's ICP-Brasil certificate). Only the original punches go in the AFD; corrections
 * belong to the AEJ (not generated yet).
 */
class AfdFile
{
    public const LAYOUT_VERSION = '003';

    /** Collector of the punch: 01 = mobile app, 02 = browser. */
    public const COLLECTOR_APP = '01';

    public const COLLECTOR_BROWSER = '02';

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

        $withoutCpf = $this->punches($from, $to)->get()->pluck('employee')->unique('id')
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
        $now = CarbonImmutable::now();
        $lines = [$this->header($settings, $from, $to, $now)];
        $count = 0;

        // The official chain covers every punch of the company from the first one, so it is recalculated from
        // the start and only the period is written.
        $previous = '';

        Punch::query()->with('employee.person')->orderBy('nsr')->chunk(500, function ($punches) use (&$lines, &$count, &$previous, $from, $to): void {
            foreach ($punches as $punch) {
                $local = $punch->localTime();
                $record = $this->nsr($punch->nsr).'7'.$this->dateTime($local).$this->cpf($punch->employee?->person)
                    .$this->dateTime($punch->created_at?->setTimezone($punch->timezone) ?? $local)
                    .($punch->source === Punch::KIOSK ? self::COLLECTOR_BROWSER : self::COLLECTOR_APP)
                    .'0';
                $previous = hash('sha256', $record.$previous);

                if ($local->toDateString() >= $from->toDateString() && $local->toDateString() <= $to->toDateString()) {
                    $lines[] = $record.$previous;
                    $count++;
                }
            }
        });

        $lines[] = '999999999'.str_repeat('0', 9 * 5).str_pad((string) $count, 9, '0', STR_PAD_LEFT).'9';
        $lines[] = 'ASSINATURA_DIGITAL_EM_ARQUIVO_P7S';

        return mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'ISO-8859-1', 'UTF-8');
    }

    public function fileName(CarbonInterface $from, CarbonInterface $to): string
    {
        return 'AFD-PREVIA-'.$from->format('Ymd').'-'.$to->format('Ymd').'.txt';
    }

    protected function header(TimeClockSetting $settings, CarbonInterface $from, CarbonInterface $to, CarbonImmutable $now): string
    {
        $developer = (string) config('kiban.timeclock.developer_document');

        return '000000000'.'1'
            .($settings->employer_document_type === 'cpf' ? '2' : '1')
            .str_pad((string) $settings->employer_document, 14, '0', STR_PAD_LEFT)
            .str_repeat(' ', 14)
            .$this->text((string) $settings->employer_name, 150)
            .$this->text((string) config('kiban.timeclock.inpi_registration'), 17)
            .$from->format('Y-m-d')
            .$to->format('Y-m-d')
            .$this->dateTime($now->setTimezone(FilamentTimezone::get()))
            .self::LAYOUT_VERSION
            .(strlen($developer) === 11 ? '2' : '1')
            .str_pad($developer, 14, '0', STR_PAD_LEFT)
            .str_repeat(' ', 30);
    }

    /**
     * @return Builder<Punch>
     */
    protected function punches(CarbonInterface $from, CarbonInterface $to)
    {
        return Punch::query()->with('employee.person')
            ->whereBetween('punched_at', [CarbonImmutable::parse($from->toDateString())->subDay()->utc(), CarbonImmutable::parse($to->toDateString())->addDays(2)->utc()]);
    }

    protected function nsr(int $nsr): string
    {
        return str_pad((string) $nsr, 9, '0', STR_PAD_LEFT);
    }

    /** "2026-11-03T08:00:00-0300" (24 characters). */
    protected function dateTime(CarbonInterface $at): string
    {
        return $at->format('Y-m-d\TH:i:sO');
    }

    protected function cpf(?Person $person): string
    {
        $cpf = Cpf::isValid((string) $person?->document) ? (string) $person->document : '';

        return str_pad($cpf, 12, '0', STR_PAD_LEFT);
    }

    protected function text(string $value, int $length): string
    {
        $value = mb_substr(trim($value), 0, $length);

        return $value.str_repeat(' ', $length - mb_strlen($value));
    }
}
