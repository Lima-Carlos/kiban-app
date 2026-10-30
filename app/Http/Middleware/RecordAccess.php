<?php

namespace App\Http\Middleware;

use App\Models\AccessLog;
use App\Support\ClientPort;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marco Civil access record: one line per session and per day (first and last moment), with IP, source port and
 * device. Written once per session per day (then only the last moment, at most every 10 minutes), so it costs
 * almost nothing per request.
 */
class RecordAccess
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();

        if ($user === null || ! $request->hasSession()) {
            return $response;
        }

        $today = now()->toDateString();
        $session = $request->session();
        $mark = $session->get('kiban.access_logged');

        if (is_array($mark) && $mark['day'] === $today && now()->getTimestamp() - $mark['at'] < 600) {
            return $response;
        }

        $tenant = $this->context->current();
        $log = AccessLog::query()->firstOrNew(['session_hash' => hash('sha256', $session->getId()), 'day' => $today]);

        if (! $log->exists) {
            $log->fill([
                'tenant_id' => $tenant?->getKey(), 'tenant_subdomain' => $tenant?->subdomain,
                'user_id' => $user->getKey(), 'user_email' => $user->email,
                'ip' => $request->ip(), 'port' => ClientPort::of($request), 'user_agent' => $request->userAgent(),
                'first_at' => now(),
            ]);
        }

        $log->last_at = now();
        $log->save();
        $session->put('kiban.access_logged', ['day' => $today, 'at' => now()->getTimestamp()]);

        return $response;
    }
}
