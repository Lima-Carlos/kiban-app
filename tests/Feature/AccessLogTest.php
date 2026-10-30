<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Marco Civil access records: one line per session and day with IP, source port and device; at least 6 months;
 * exported only by command (court order).
 */
class AccessLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_record_per_session_and_day_with_ip_and_port(): void
    {
        $tenant = $this->tenant('padaria');
        $user = $this->signIn($tenant);

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5', 'REMOTE_PORT' => '41000'])
            ->withHeaders(['X-Forwarded-For' => '200.150.10.20', 'X-Client-Port' => '53111'])
            ->get($tenant->url('/'))->assertOk();
        $this->get($tenant->url('/'))->assertOk();

        $log = AccessLog::sole();
        $this->assertSame([$tenant->id, 'padaria', $user->id], [$log->tenant_id, $log->tenant_subdomain, $log->user_id]);
        // Behind the internal proxy: the visitor's real IP and port.
        $this->assertSame(['200.150.10.20', 53111], [$log->ip, $log->port]);
        $this->assertSame(now()->toDateString(), $log->day->toDateString());
    }

    public function test_headers_from_outside_the_internal_network_are_ignored(): void
    {
        $tenant = $this->tenant('padaria');
        $this->signIn($tenant);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'REMOTE_PORT' => '40000'])
            ->withHeaders(['X-Forwarded-For' => '1.2.3.4', 'X-Client-Port' => '60001'])
            ->get($tenant->url('/'))->assertOk();

        $this->assertSame(['203.0.113.9', 40000], [AccessLog::sole()->ip, AccessLog::sole()->port]);
    }

    public function test_kept_at_least_six_months_and_exported_by_command(): void
    {
        Storage::fake('local');
        config(['kiban.access_log_days' => 30]); // a setting below the legal minimum is ignored
        $base = ['session_hash' => 'x', 'user_email' => 'ana@exemplo.test', 'ip' => '200.1.1.1', 'port' => 50000, 'first_at' => now(), 'last_at' => now()];
        AccessLog::create(['day' => now()->subDays(100)->toDateString(), 'session_hash' => 'a'] + $base);
        AccessLog::create(['day' => now()->subDays(200)->toDateString(), 'session_hash' => 'b'] + $base);

        $this->artisan('model:prune', ['--model' => [AccessLog::class]])->assertSuccessful();
        $this->assertSame(1, AccessLog::count()); // 100 days stays (under 6 months), 200 days goes

        $this->artisan('kiban:access-logs', ['--email' => 'ana@exemplo.test'])->assertSuccessful();
        $file = Storage::disk('local')->files('exports')[0];
        $this->assertStringContainsString('200.1.1.1;50000', Storage::disk('local')->get($file));
    }
}
