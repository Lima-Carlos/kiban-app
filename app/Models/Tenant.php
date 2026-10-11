<?php

namespace App\Models;

use App\Licensing\License;
use App\Licensing\LicenseVerifier;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A customer company of the Kiban Core, reached at <subdomain>.<tenant_domain>.
 * Created automatically the first time the Core confirms the address.
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $fillable = ['uuid', 'subdomain', 'name', 'license_token', 'license_fetched_at', 'license_expires_at', 'license_failed_at', 'onboarding_reported'];

    protected $hidden = ['license_token'];

    protected ?License $license = null;

    protected function casts(): array
    {
        return [
            'license_fetched_at' => 'datetime',
            'license_expires_at' => 'datetime',
            'license_failed_at' => 'datetime',
            'onboarding_reported' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['role', 'last_seen_at'])->withTimestamps();
    }

    /**
     * The license, checked again (signature and dates) each time it is loaded: a license that expired
     * or was tampered with in the database gives no access.
     */
    public function license(): License
    {
        return $this->license ??= app(LicenseVerifier::class)->toLicense($this->license_token);
    }

    public function forgetLicense(): void
    {
        $this->license = null;
    }

    public function url(string $path = '/'): string
    {
        $base = parse_url((string) config('app.url'));
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        return ($base['scheme'] ?? 'https').'://'.$this->subdomain.'.'.config('kiban.tenant_domain').$port.'/'.ltrim($path, '/');
    }
}
