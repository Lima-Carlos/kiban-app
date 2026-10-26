<?php

namespace App\Modules\TimeClock\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The photo of a punch, taken by the live camera (phone selfie or tablet) and sent as a JPEG/PNG data URL.
 * Kept in the company's private folder; anything that is not a real image (or over 3 MB) is ignored and the
 * punch is flagged "no photo" (it is never blocked).
 */
class PunchPhotoStore
{
    public const MAX_BYTES = 3 * 1024 * 1024;

    public function __construct(protected TenantContext $context) {}

    public function fromDataUrl(?string $dataUrl): ?string
    {
        if (! is_string($dataUrl) || ! preg_match('#^data:image/(jpeg|png);base64,(.+)$#', $dataUrl, $match)) {
            return null;
        }

        $bytes = base64_decode($match[2], true);

        if ($bytes === false || strlen($bytes) > self::MAX_BYTES || @getimagesizefromstring($bytes) === false) {
            return null;
        }

        $path = $this->folder().'/'.Str::uuid().'.'.($match[1] === 'png' ? 'png' : 'jpg');
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    public function folder(): string
    {
        return 'tenants/'.$this->context->get()->getKey().'/timeclock/punches';
    }
}
