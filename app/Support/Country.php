<?php

namespace App\Support;

use App\Tenancy\TenantContext;

/**
 * The company's country (from its registration in the Core) and what depends on it: the mobile number's
 * country code and whether Brazilian rules apply (CPF, official time clock files...).
 */
class Country
{
    public const BRAZIL = 'BR';

    /** International dialing codes of the countries we sell to first. */
    public const CALLING_CODES = [
        'BR' => '55', 'US' => '1', 'CA' => '1', 'PT' => '351', 'ES' => '34', 'MX' => '52', 'AR' => '54', 'CL' => '56',
        'CO' => '57', 'PE' => '51', 'UY' => '598', 'PY' => '595', 'BO' => '591', 'EC' => '593', 'GB' => '44',
        'FR' => '33', 'DE' => '49', 'IT' => '39',
    ];

    /** Currency of each country (others: US dollar). */
    public const CURRENCIES = [
        'BR' => 'BRL', 'US' => 'USD', 'PT' => 'EUR', 'ES' => 'EUR', 'FR' => 'EUR', 'DE' => 'EUR', 'IT' => 'EUR',
        'MX' => 'MXN', 'AR' => 'ARS', 'CL' => 'CLP', 'CO' => 'COP', 'PE' => 'PEN', 'UY' => 'UYU', 'PY' => 'PYG',
        'GB' => 'GBP', 'CA' => 'CAD', 'BO' => 'BOB', 'EC' => 'USD',
    ];

    public static function currency(?string $country = null): string
    {
        return self::CURRENCIES[strtoupper($country ?? static::current())] ?? 'USD';
    }

    /** A currency in words, in the person's language: "Real brasileiro (R$)" instead of "BRL". */
    public static function currencyName(string $code, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $name = \ResourceBundle::create($locale, 'ICUDATA-curr')?->get('Currencies')?->get($code)?->get(1);
        $symbol = (new \NumberFormatter($locale.'@currency='.$code, \NumberFormatter::CURRENCY))->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);

        return $name ? mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1).' ('.($symbol ?: $code).')' : $code;
    }

    /** The current company's country (ISO code, e.g. "BR"). */
    public static function current(): string
    {
        $tenant = app(TenantContext::class)->current();

        return strtoupper((string) ($tenant?->license()->tenant('country') ?: self::BRAZIL));
    }

    public static function isBrazil(?string $country = null): bool
    {
        return strtoupper($country ?? static::current()) === self::BRAZIL;
    }

    public static function callingCode(?string $country = null): ?string
    {
        return self::CALLING_CODES[strtoupper($country ?? static::current())] ?? null;
    }

    /**
     * Mobile as digits with the country code, like the Core keeps it. A number typed with "+" (or "00") is
     * already international; otherwise it gets the company country's code (unless it already starts with it).
     */
    public static function phone(?string $phone, ?string $country = null): ?string
    {
        $typed = trim((string) $phone);
        $digits = preg_replace('/\D/', '', $typed);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($typed, '+')) {
            return $digits;
        }

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        $code = static::callingCode($country);

        if ($code === null) {
            return $digits;
        }

        // Brazil: 10 or 11 digits with the area code. Already with "55" in front: 12 or 13 digits.
        if ($code === '55') {
            return in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, '55') ? $digits : '55'.$digits;
        }

        return str_starts_with($digits, $code) && strlen($digits) > 10 ? $digits : $code.$digits;
    }

    /** A kept number (digits with the country code) as people read it: "+55 (31) 98888-1234", "+52 5500000000". */
    public static function formatPhone(?string $digits): ?string
    {
        if (blank($digits)) {
            return null;
        }

        if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $digits, $parts)) {
            return "+55 ({$parts[1]}) {$parts[2]}-{$parts[3]}";
        }

        $code = collect(self::CALLING_CODES)->unique()->sortByDesc(fn (string $code): int => strlen($code))->first(fn (string $code): bool => str_starts_with($digits, $code));

        return $code === null ? '+'.$digits : '+'.$code.' '.substr($digits, strlen($code));
    }
}
