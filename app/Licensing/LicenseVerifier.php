<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Storage;
use Throwable;
use UnexpectedValueException;

/**
 * Checks a license with the Core public key: signature (RS256), audience, issuer and dates.
 *
 * The public key is downloaded once from the Core and kept on disk, so licenses can be checked
 * while the Core is down. In production it can be fixed in KIBAN_CORE_PUBLIC_KEY.
 */
class LicenseVerifier
{
    public const AUDIENCE = 'kiban-app';

    protected const KEY_FILE = 'kiban/core-license-key.json';

    public function __construct(protected CoreClient $core) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ExpiredException when the license expired
     * @throws UnexpectedValueException when it is not a valid Core license
     */
    public function verify(string $token): array
    {
        JWT::$timestamp = CarbonImmutable::now()->getTimestamp();

        try {
            $decoded = JWT::decode($token, new Key($this->publicKey($this->keyIdOf($token)), 'RS256'));
        } finally {
            JWT::$timestamp = null;
        }

        $claims = (array) json_decode((string) json_encode($decoded), true);

        if (($claims['aud'] ?? null) !== self::AUDIENCE) {
            throw new UnexpectedValueException('License for another audience.');
        }

        if (($claims['iss'] ?? null) !== config('kiban.core.url')) {
            throw new UnexpectedValueException('License issued by another server.');
        }

        return $claims;
    }

    /**
     * Never throws: a missing, expired or invalid license becomes a License without access.
     */
    public function toLicense(?string $token): License
    {
        if ($token === null || $token === '') {
            return License::none();
        }

        try {
            return new License(LicenseState::Valid, $this->verify($token));
        } catch (ExpiredException) {
            return License::none(LicenseState::Expired);
        } catch (CoreUnavailableException) {
            // No public key yet and the Core is down: cannot check it, so it is not trusted.
            return License::none(LicenseState::Missing);
        } catch (Throwable) {
            return License::none(LicenseState::Invalid);
        }
    }

    /**
     * Public key matching the license's key id. Downloaded again only when the Core changed its keys.
     */
    public function publicKey(?string $kid = null): string
    {
        if (filled($pinned = config('kiban.core.public_key'))) {
            return str_replace('\\n', "\n", (string) $pinned);
        }

        $stored = $this->storedKey();

        if ($stored !== null && ($kid === null || $stored['kid'] === $kid)) {
            return $stored['public_key'];
        }

        $fresh = $this->core->licenseKey();
        Storage::disk('local')->put(self::KEY_FILE, (string) json_encode(['kid' => $fresh['kid'], 'public_key' => $fresh['public_key']]));

        return $fresh['public_key'];
    }

    /**
     * @return array{kid: string, public_key: string}|null
     */
    protected function storedKey(): ?array
    {
        $content = Storage::disk('local')->get(self::KEY_FILE);
        $data = $content ? json_decode($content, true) : null;

        return is_array($data) && isset($data['kid'], $data['public_key']) ? $data : null;
    }

    protected function keyIdOf(string $token): ?string
    {
        $header = json_decode((string) JWT::urlsafeB64Decode(explode('.', $token)[0] ?? ''), true);

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }
}
