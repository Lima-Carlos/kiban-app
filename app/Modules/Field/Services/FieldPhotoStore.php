<?php

namespace App\Modules\Field\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photos and signatures of the work orders, sent by the phone as JPEG/PNG data URLs (already reduced on the
 * phone). Kept in the company's private folder; anything that is not a real image (or over 5 MB) is refused.
 */
class FieldPhotoStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(protected TenantContext $context) {}

    public function fromDataUrl(?string $dataUrl, int $orderId): ?string
    {
        if (! is_string($dataUrl) || ! preg_match('#^data:image/(jpeg|png);base64,(.+)$#', $dataUrl, $match)) {
            return null;
        }

        $bytes = base64_decode($match[2], true);

        if ($bytes === false || strlen($bytes) > self::MAX_BYTES || @getimagesizefromstring($bytes) === false) {
            return null;
        }

        $path = 'tenants/'.$this->context->get()->getKey().'/field/orders/'.$orderId.'/'.Str::uuid().'.'.($match[1] === 'png' ? 'png' : 'jpg');
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }
}
