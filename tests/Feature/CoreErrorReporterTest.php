<?php

namespace Tests\Feature;

use App\Licensing\CoreErrorReporter;
use App\Licensing\CoreUnavailableException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The errors of the systems go to the Kiban Core list and the owner's Telegram (28/11/2026), at most once every
 * 10 minutes per kind, and never for "Core unreachable".
 */
class CoreErrorReporterTest extends TestCase
{
    public function test_an_error_goes_to_the_core_once_per_kind_with_the_place_in_our_code(): void
    {
        config(['kiban.core.report_errors' => true]);
        $sent = [];
        $this->externalServices['/api/v1/errors'] = function (Request $request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response(['ok' => true], 202);
        };

        $reporter = app(CoreErrorReporter::class);
        $error = new RuntimeException('Falhou o pedido');
        $reporter->report($error);
        $reporter->report($error);
        $reporter->report(new CoreUnavailableException('Core fora'));
        // The dispatch "after the response" runs when the app terminates.
        $this->app->terminate();

        $this->assertCount(1, $sent);
        $this->assertSame('kiban-app', $sent[0]['system']);
        $this->assertSame(RuntimeException::class, $sent[0]['exception']);
        $this->assertSame('Falhou o pedido', $sent[0]['message']);
        $this->assertStringStartsWith('tests/Feature/CoreErrorReporterTest.php:', $sent[0]['location']);
    }

    public function test_switched_off_nothing_is_sent(): void
    {
        $sent = 0;
        $this->externalServices['/api/v1/errors'] = function () use (&$sent) {
            $sent++;

            return Http::response(['ok' => true], 202);
        };

        app(CoreErrorReporter::class)->report(new RuntimeException('x'));
        $this->app->terminate();

        $this->assertSame(0, $sent);
    }
}
