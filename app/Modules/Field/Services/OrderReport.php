<?php

namespace App\Modules\Field\Services;

use App\Models\CompanyProfile;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\OrderPhoto;
use App\Modules\Field\Models\WorkOrder;
use App\People\PeopleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The report of a done work order, with the company's identity (logo, color, data and footer): what was done,
 * the checklist answers, materials, photos and the customer's signature, plus a verification code (made with the
 * system's secret key: a changed copy does not match). The customer gets it by a link (WhatsApp) and can download
 * the PDF. The link is signed: it only works as it was made, on the company's address.
 */
class OrderReport
{
    /** Photos inside the report (the first ones; all of them stay in the system). */
    public const MAX_PHOTOS = 12;

    public function url(WorkOrder $order): string
    {
        return URL::signedRoute('field.report', ['order' => $order->getKey()]);
    }

    public function pdfUrl(WorkOrder $order): string
    {
        return URL::signedRoute('field.report.pdf', ['order' => $order->getKey()]);
    }

    public function whatsappUrl(WorkOrder $order): string
    {
        return PeopleService::whatsappUrl($order->customer->phone, __('field.report.whatsapp_message', [
            'name' => Str::before($order->customer->name, ' '),
            'company' => CompanyProfile::current()->name(),
            'order' => $order->label(),
            'link' => $this->url($order),
        ]));
    }

    /** 16 characters in 4 groups, e.g. "3F9A-0C2B-77D1-A0E4". */
    public function verificationCode(WorkOrder $order): string
    {
        $code = strtoupper(substr(hash_hmac('sha256', implode('|', [$order->tenant_id, $order->getKey(), $order->number, $order->finished_at?->toIso8601ZuluString()]), (string) config('app.key')), 0, 16));

        return implode('-', str_split($code, 4));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(WorkOrder $order): array
    {
        $order->loadMissing(['customer', 'site', 'serviceType', 'technician', 'answers', 'photos', 'materials']);
        $profile = CompanyProfile::current();
        $timezone = OrderResource::timezone();
        $image = fn (?string $path): ?string => $path !== null && Storage::disk('local')->exists($path)
            ? 'data:'.(Storage::disk('local')->mimeType($path) ?: 'image/jpeg').';base64,'.base64_encode(Storage::disk('local')->get($path))
            : null;

        return [
            'order' => $order,
            'profile' => $profile,
            'company' => $profile->name(),
            'color' => $profile->color(),
            'logo' => $profile->logoDataUrl(),
            'answers' => OrderResource::answerLines($order),
            'photos' => $order->photos->take(self::MAX_PHOTOS)->map(fn (OrderPhoto $photo): ?string => $image($photo->path))->filter()->values()->all(),
            'morePhotos' => max(0, $order->photos->count() - self::MAX_PHOTOS),
            'signature' => $image($order->signature_path),
            'arrived' => $order->checked_in_at?->setTimezone($timezone),
            'finished' => $order->finished_at?->setTimezone($timezone),
            'code' => $this->verificationCode($order),
            'pdfUrl' => $this->pdfUrl($order),
            'rateUrl' => URL::signedRoute('field.report.rate', ['order' => $order->getKey()]),
        ];
    }

    public function pdf(WorkOrder $order): string
    {
        return Pdf::setOption('isFontSubsettingEnabled', true)->loadView('field.report-pdf', $this->data($order))->setPaper('a4')->output();
    }

    public function fileName(WorkOrder $order): string
    {
        return Str::slug(__('field.report.file', ['number' => $order->number])).'.pdf';
    }
}
