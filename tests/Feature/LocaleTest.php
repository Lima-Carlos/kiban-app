<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every app lets the person switch the language (Português, English, Español).
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_language_in_the_app(): void
    {
        $tenant = $this->tenant();
        $user = $this->signIn($tenant);

        $this->get($tenant->url('/'))->assertOk()->assertSee('Início');

        $this->from($tenant->url('/'))->get($tenant->url('/idioma/en'))->assertRedirect($tenant->url('/'));
        $this->assertSame('en', $user->fresh()->app_locale);
        $this->get($tenant->url('/'))->assertOk()->assertSee('Home')->assertSee('Español');

        // The choice wins over the language of the Kiban account.
        $user->forceFill(['locale' => 'es'])->save();
        $this->get($tenant->url('/'))->assertSee('Home');

        // Unknown language, or coming from another site: no harm.
        $this->get($tenant->url('/idioma/xx'))->assertNotFound();
        $this->from('https://outro-site.test/x')->get($tenant->url('/idioma/es'))->assertRedirect('/');
    }

    public function test_simple_pages_have_the_language_links(): void
    {
        // A browser in Portuguese; the page follows it until the person picks another language.
        $this->withHeader('Accept-Language', 'pt-BR,pt;q=0.9');

        $this->get('http://app.kiban.test/entrar')->assertOk()->assertSee('Entrar no sistema')->assertSee('English');
        $this->from('http://app.kiban.test/entrar')->get('http://app.kiban.test/idioma/es')->assertRedirect('http://app.kiban.test/entrar');
        $this->get('http://app.kiban.test/entrar')->assertOk()->assertSee('Entrar al sistema');
    }
}
