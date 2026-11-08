<?php

namespace Tests\Feature;

use App\Support\Money;
use Tests\TestCase;

/** Money looks the same in every language: reais are always "R$ 31,20" (no "31,20 BRL" or "R$31.20"). */
class MoneyTest extends TestCase
{
    public function test_reais_look_the_same_in_every_language(): void
    {
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            $this->assertSame("R$\u{a0}31,20", Money::format(3120, 'BRL'), $locale);
            $this->assertSame('R$', Money::symbol('BRL'), $locale);
        }

        $this->assertSame('—', Money::format(null));
    }

    public function test_other_currencies_follow_their_own_country(): void
    {
        app()->setLocale('pt_BR');

        $this->assertSame('$1,234.50', Money::format(123450, 'MXN'));
        $this->assertSame('$', Money::symbol('MXN'));
    }
}
