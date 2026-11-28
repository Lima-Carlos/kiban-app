<?php

namespace App\Licensing;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends the errors of this app to the Kiban Core (28/11/2026): they join the Core list, the explanation by Claude
 * and the owner's Telegram. Before, nobody watched the errors inside Frota, Ponto, Campo...
 *
 * - The same kind of error (exception + place) goes at most once every 10 minutes (no flood).
 * - Sent after the answer to the visitor (never makes a page slower) and never throws: the error still goes to the
 *   log as usual. The Core masks names, e-mails and documents again before keeping the message.
 * - Off in the tests (KIBAN_REPORT_ERRORS), without the Core keys and for "Core unreachable" (it could not arrive anyway).
 */
class CoreErrorReporter
{
    public const SYSTEM = 'kiban-app';

    protected const THROTTLE_SECONDS = 600;

    public function report(Throwable $exception): void
    {
        try {
            if ($exception instanceof CoreUnavailableException || ! config('kiban.core.report_errors')
                || blank(config('kiban.core.client_id')) || blank(config('kiban.core.client_secret'))) {
                return;
            }

            $location = $this->location($exception);

            if (! Cache::add('core-error:'.sha1(get_class($exception).'|'.$location), true, self::THROTTLE_SECONDS)) {
                return;
            }

            $error = [
                'system' => self::SYSTEM,
                'exception' => get_class($exception),
                'message' => mb_substr($exception->getMessage(), 0, 2000),
                'location' => mb_substr($location, 0, 200),
                'url' => app()->runningInConsole() ? 'console' : mb_substr(request()->fullUrl(), 0, 500),
            ];

            dispatch(function () use ($error): void {
                try {
                    app(CoreClient::class)->reportError($error);
                } catch (Throwable) {
                    // The Core is down or refused it: nothing else to do (the log has it).
                }
            })->afterResponse();
        } catch (Throwable) {
            // Reporting must never break the page.
        }
    }

    /** The first place in our own code (app/... or routes/...), the same rule as the Core. */
    public function location(Throwable $exception): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;
        $frames = array_merge([['file' => $exception->getFile(), 'line' => $exception->getLine()]], $exception->getTrace());

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? '';

            if (str_starts_with($file, $base.'app'.DIRECTORY_SEPARATOR) || str_starts_with($file, $base.'routes'.DIRECTORY_SEPARATOR)) {
                return str_replace([$base, '\\'], ['', '/'], $file).':'.($frame['line'] ?? 0);
            }
        }

        return str_replace([$base, '\\'], ['', '/'], $exception->getFile()).':'.$exception->getLine();
    }
}
