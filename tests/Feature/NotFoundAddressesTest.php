<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Addresses typed by hand that do not exist ("/frota/veiculos/create") are "page not found" (404),
 * never an error 500 from the database.
 */
class NotFoundAddressesTest extends TestCase
{
    use RefreshDatabase;

    public function test_words_where_a_record_number_goes_are_not_found(): void
    {
        $company = $this->tenant('aurora', ['frota' => [], 'campo' => [], 'ponto' => []]);
        $this->signIn($company);

        foreach ([
            '/frota/veiculos/create',
            '/frota/veiculos/create/editar',
            '/campo/ordens/create',
            '/campo/clientes/abc/editar',
            '/campo/minhas-os/abc',
            '/frota/veiculos/999999',
            '/campo/ordens/999999',
        ] as $path) {
            $this->get($company->url($path))->assertNotFound();
        }

        // The real screens still open.
        $this->get($company->url('/frota/veiculos/novo'))->assertOk();
        $this->get($company->url('/campo/clientes/novo'))->assertOk();
    }
}
