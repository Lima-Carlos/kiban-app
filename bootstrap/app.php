<?php

use App\Http\Kernel;
use App\Http\Middleware\EnsureCentral;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a proxy that adds the padlock (Caddy in the external tests, the VPS later): trust it to say the
        // visit came by HTTPS and by which address and port (TRUSTED_PROXIES).
        // Default: only the internal network (the PC, Docker and the server's own proxy), never the internet, so nobody
        // can fake the IP and port kept in the access records (Marco Civil). "*" trusts anyone: never in production.
        $proxies = (string) env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');
        $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));

        $middleware->alias([
            'tenant' => IdentifyTenant::class,
            'central' => EnsureCentral::class,
            'locale' => SetLocale::class,
        ]);

        // The time clock tablet stays on the same screen for hours: its two actions are protected by the tablet's
        // secret (and a short ticket), not by the form token that expires with the session.
        $middleware->validateCsrfTokens(except: ['relogio/pin', 'relogio/bater', 'relogio/ciente']);

        // Not signed in: go through the Core sign-in of the company.
        $middleware->redirectGuestsTo(fn (): string => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// Single address: the company comes in the path (app.../<company>/...), read at the entrance of every visit.
$app->singleton(HttpKernelContract::class, Kernel::class);

return $app;
