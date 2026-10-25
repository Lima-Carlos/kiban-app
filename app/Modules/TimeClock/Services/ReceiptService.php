<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Punch;
use App\Tenancy\TenantContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;

/**
 * The receipt of each punch: company, person, number (NSR), date and time where it happened, place, and a
 * verification code made from the punch's hash with the system's secret key (a copied or edited receipt does
 * not match). Available to the employee in "My time clock", and by a QR code on the tablet (for people
 * without a login), at a signed address.
 */
class ReceiptService
{
    public function __construct(protected TenantContext $context) {}

    /** 16 characters in 4 groups, e.g. "3F9A-0C2B-77D1-A0E4". */
    public function verificationCode(Punch $punch): string
    {
        $code = strtoupper(substr(hash_hmac('sha256', $punch->hash.'|'.$punch->nsr, (string) config('app.key')), 0, 16));

        return implode('-', str_split($code, 4));
    }

    /** Signed address of the receipt on the company's address (works without a login). */
    public function url(Punch $punch): string
    {
        return $this->context->get()->url(ltrim(URL::signedRoute('timeclock.receipt', ['punch' => $punch->getKey()], absolute: false), '/'));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Punch $punch): array
    {
        $punch->loadMissing(['employee.person', 'employee.workplace', 'kiosk.workplace']);

        return [
            'punch' => $punch,
            'company' => $this->context->get()->name,
            'local' => $punch->localTime(),
            'code' => $this->verificationCode($punch),
            'workplace' => $punch->kiosk?->workplace ?? $punch->employee?->workplace,
        ];
    }

    public function pdf(Punch $punch): string
    {
        return Pdf::setOption('isFontSubsettingEnabled', true)->loadView('timeclock.receipt-pdf', $this->data($punch))->setPaper([0, 0, 300, 520])->output();
    }

    public function fileName(Punch $punch): string
    {
        return 'comprovante-ponto-'.$punch->nsr.'.pdf';
    }

    /** QR code (SVG) with the signed address of the receipt, for the tablet screen. */
    public function qrCode(Punch $punch, int $size = 180): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return $writer->writeString($this->url($punch));
    }
}
