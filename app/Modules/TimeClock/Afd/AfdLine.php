<?php

namespace App\Modules\TimeClock\Afd;

use App\Rules\Cpf;
use Carbon\CarbonInterface;

/**
 * Brazil: the fixed-width lines of the AFD (Arquivo Fonte de Dados) of a REP-P, layout 004 of the Ministry of
 * Labour (31/07/2026; the positions are the same as 003). Fields are left-aligned and padded with spaces
 * (numbers zero-padded, as the market does), date-times "AAAA-MM-ddThh:mm:00-0300" (seconds always 00),
 * types 1 to 5 end with a CRC-16/KERMIT in hex and type 7 with a SHA-256 chained to the previous punch.
 * Pure functions: the migration that fills the old punches uses them too.
 */
class AfdLine
{
    public const VERSION = '004';

    /** Collector of a REP-P punch: 01 mobile app, 02 browser. */
    public const COLLECTOR_APP = '01';

    public const COLLECTOR_BROWSER = '02';

    public const SIGNATURE = 'ASSINATURA_DIGITAL_EM_ARQUIVO_P7S';

    /** Type 7 without the hash (positions 1 to 73): the part kept with the punch and hashed. */
    public static function punch(int $nsr, CarbonInterface $at, ?string $cpf, CarbonInterface $recordedAt, string $collector, bool $offline): string
    {
        return static::nsr($nsr).'7'.static::dateTime($at).static::cpf($cpf).static::dateTime($recordedAt).$collector.($offline ? '1' : '0');
    }

    /** SHA-256 of the punch line followed by the hash of the previous punch (nothing for the first one). */
    public static function punchHash(string $line, ?string $previous): string
    {
        return hash('sha256', static::latin1($line.($previous ?? '')));
    }

    /** Type 2: inclusion or change of the employer. */
    public static function employer(int $nsr, CarbonInterface $at, ?string $responsibleCpf, string $documentType, string $document, string $name, string $place): string
    {
        return static::withCrc(static::nsr($nsr).'2'.static::dateTime($at).static::digits(static::cpfDigits($responsibleCpf), 14)
            .($documentType === 'cpf' ? '2' : '1').static::text(static::document($document), 14).str_repeat(' ', 14)
            .static::text($name, 150).static::text($place, 100));
    }

    /** Type 5: employee included (I), changed (A) or excluded (E). */
    public static function employee(int $nsr, CarbonInterface $at, string $operation, ?string $cpf, string $name, ?string $responsibleCpf): string
    {
        return static::withCrc(static::nsr($nsr).'5'.static::dateTime($at).$operation.static::cpf($cpf).static::text($name, 52)
            .str_repeat(' ', 4).static::digits(static::cpfDigits($responsibleCpf), 11));
    }

    /** Type 1. */
    public static function header(string $documentType, string $document, string $name, string $inpi, CarbonInterface $from, CarbonInterface $to, CarbonInterface $now, string $developer): string
    {
        $developer = static::document($developer);

        return static::withCrc('000000000'.'1'.($documentType === 'cpf' ? '2' : '1').static::text(static::document($document), 14).str_repeat(' ', 14)
            .static::text($name, 150).static::text(static::inpi($inpi), 17).$from->format('Y-m-d').$to->format('Y-m-d')
            .static::dateTime($now).self::VERSION.(strlen($developer) === 11 ? '2' : '1').static::text($developer, 14).str_repeat(' ', 30));
    }

    /**
     * Type 9: how many records of types 2 to 7.
     *
     * @param  array<int, int>  $counts  type => count
     */
    public static function trailer(array $counts): string
    {
        $line = '999999999';

        foreach ([2, 3, 4, 5, 6, 7] as $type) {
            $line .= str_pad((string) ($counts[$type] ?? 0), 9, '0', STR_PAD_LEFT);
        }

        return $line.'9';
    }

    public static function signature(): string
    {
        return str_pad(self::SIGNATURE, 100);
    }

    /** "2026-11-03T08:00:00-0300" (24 characters, seconds always 00). */
    public static function dateTime(CarbonInterface $at): string
    {
        return $at->format('Y-m-d\TH:i:00O');
    }

    /** CRC-16/KERMIT (poly 0x1021 reflected, init 0): "123456789" gives "2189". */
    public static function crc(string $value): string
    {
        $crc = 0;

        foreach (str_split(static::latin1($value)) as $char) {
            $crc ^= ord($char);

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 1) ? (($crc >> 1) ^ 0x8408) : ($crc >> 1);
            }
        }

        return sprintf('%04X', $crc);
    }

    public static function withCrc(string $line): string
    {
        return $line.static::crc($line);
    }

    /** The employee's CPF in 12 positions ("0" + 11 digits); zeros when the CPF is not valid. */
    public static function cpf(?string $cpf): string
    {
        return '0'.static::digits(static::cpfDigits($cpf), 11);
    }

    public static function cpfDigits(?string $cpf): string
    {
        $digits = (string) Cpf::digits((string) $cpf);

        return Cpf::isValid($digits) ? $digits : '';
    }

    /** CNPJ (digits or the new letters) or CPF without punctuation. */
    public static function document(string $document): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $document));
    }

    /** INPI number: only digits and letters, without "BR" and punctuation ("BR 51 2022 000123-4" → "5120220001234"). */
    public static function inpi(string $number): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', (string) preg_replace('/^\s*BR/i', '', strtoupper($number)));
    }

    public static function nsr(int $nsr): string
    {
        return str_pad((string) $nsr, 9, '0', STR_PAD_LEFT);
    }

    /** A number zero-padded on the left (all zeros when empty). */
    public static function digits(string $value, int $length): string
    {
        return str_pad(substr($value, -$length), $length, '0', STR_PAD_LEFT);
    }

    /** Text left-aligned and padded with spaces, counted in ISO-8859-1 characters (accents count as one). */
    public static function text(string $value, int $length): string
    {
        $value = mb_substr(trim(str_replace(["\r", "\n", "\t"], ' ', $value)), 0, $length);

        return $value.str_repeat(' ', $length - mb_strlen($value));
    }

    public static function latin1(string $value): string
    {
        return mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
    }
}
