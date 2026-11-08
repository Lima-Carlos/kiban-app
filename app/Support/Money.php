<?php

namespace App\Support;

use NumberFormatter;

/**
 * Money on screen, the same in every language: the amount is written the way its own country writes it
 * ("R$ 31,20" for reais, "$1,234.50" for Mexican pesos), whatever the language of who is looking.
 * Before, an English or Spanish screen showed "R$31.20" or "31,20 BRL", which looked like another currency.
 */
class Money
{
    /** Language of each country (others: Spanish). Only used to pick how the country writes its money. */
    public const LANGUAGES = ['BR' => 'pt', 'PT' => 'pt', 'US' => 'en', 'GB' => 'en', 'CA' => 'en', 'FR' => 'fr', 'DE' => 'de', 'IT' => 'it'];

    /** "R$ 31,20". Null becomes "—". The currency defaults to the company's. */
    public static function format(?int $cents, ?string $currency = null): string
    {
        if ($cents === null) {
            return '—';
        }

        $currency ??= Country::currency();

        return (string) (new NumberFormatter(static::locale($currency), NumberFormatter::CURRENCY))->formatCurrency($cents / 100, $currency);
    }

    /** "R$", for the prefix of money fields. */
    public static function symbol(?string $currency = null): string
    {
        $currency ??= Country::currency();
        $symbol = (new NumberFormatter(static::locale($currency).'@currency='.$currency, NumberFormatter::CURRENCY))->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        return $symbol !== '' ? $symbol : $currency;
    }

    /** The company's country when the currency is its own; otherwise the first country that uses it. */
    protected static function locale(string $currency): string
    {
        $country = Country::current();

        if (Country::currency($country) !== $currency) {
            $country = array_search($currency, Country::CURRENCIES, true) ?: 'US';
        }

        return (self::LANGUAGES[$country] ?? 'es').'_'.$country;
    }
}
