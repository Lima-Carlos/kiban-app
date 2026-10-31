<?php

namespace App\People\Epi;

use App\Models\CompanyProfile;
use App\People\Person;
use App\Support\Country;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * The PPE record of a person in PDF (the usual NR-6 format in Brazil): the company (its identity), the person,
 * one line per item delivered (day, item, CA, quantity, reason, signature) and the returns, plus the
 * acknowledgment text and the check code of each signed delivery. To download and print.
 */
class EpiRecord
{
    public function __construct(protected EpiService $epi) {}

    /**
     * @return array<string, mixed>
     */
    public function data(Person $person): array
    {
        $profile = CompanyProfile::current();
        $deliveries = EpiDelivery::query()->with(['lines.returns'])->where('person_id', $person->getKey())
            ->orderBy('delivered_on')->orderBy('id')->get();

        return [
            'person' => $person->loadMissing('team'),
            'profile' => $profile,
            'company' => $profile->name(),
            'color' => $profile->color(),
            'logo' => $profile->logoDataUrl(),
            'brazil' => Country::isBrazil(),
            'deliveries' => $deliveries,
            'signatures' => $deliveries->mapWithKeys(fn (EpiDelivery $delivery): array => [$delivery->getKey() => $this->epi->signatureDataUrl($delivery)])->all(),
            'codes' => $deliveries->mapWithKeys(fn (EpiDelivery $delivery): array => [$delivery->getKey() => $this->epi->code($delivery)])->all(),
            'terms' => $deliveries->whereNotNull('terms')->last()?->terms ?? $this->epi->terms(),
            'timezone' => $this->epi->timezone(),
            'generated' => now($this->epi->timezone()),
        ];
    }

    public function pdf(Person $person): string
    {
        return Pdf::setOption('isFontSubsettingEnabled', true)
            ->loadView('epi.record-pdf', $this->data($person))
            ->setPaper('a4', 'landscape')
            ->output();
    }

    public function fileName(Person $person): string
    {
        return Str::slug(__('epi.record.file', ['name' => $person->name])).'.pdf';
    }
}
