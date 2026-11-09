<?php

namespace Tests\Feature;

use App\Filament\Pages\AskPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Ask your system": hidden while off; on, the question goes to the Core with the weekly numbers and the answer shows.
 */
class AskPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_off_by_default_and_answers_when_on(): void
    {
        $company = $this->tenant('instaladora', ['ponto' => []]);
        $this->signIn($company);

        $this->assertFalse(AskPage::canAccess());

        config(['kiban.ask_enabled' => true]);
        $this->assertTrue(AskPage::canAccess());

        Livewire::test(AskPage::class)
            ->fillForm(['question' => 'Quantas horas extras na semana passada?'])
            ->call('ask')
            ->assertSee('Foram 2:00 de horas extras na semana passada.');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/tenants/instaladora/ask')
            && $request['question'] === 'Quantas horas extras na semana passada?' && count($request['weeks']) === AskPage::WEEKS);
    }
}
