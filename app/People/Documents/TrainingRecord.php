<?php

namespace App\People\Documents;

use App\Models\CompanyProfile;
use App\People\Person;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Str;

/**
 * "Comprovante de cursos" in PDF: the company (its identity), the person and their valid courses (name, date,
 * validity, institution, hours). The manager sends it to a client who asks for proof before a job. Internal only:
 * never on a public or client link (LGPD).
 */
class TrainingRecord
{
    public function __construct(protected TrainingService $trainings) {}

    /**
     * @return array<string, mixed>
     */
    public function data(Person $person): array
    {
        $profile = CompanyProfile::current();

        return [
            'person' => $person->loadMissing('team'),
            'profile' => $profile,
            'company' => $profile->name(),
            'color' => $profile->color(),
            'logo' => $profile->logoDataUrl(),
            'courses' => $this->trainings->validCourses($person),
            'generated' => now(FilamentTimezone::get()),
        ];
    }

    public function pdf(Person $person): string
    {
        return Pdf::setOption('isFontSubsettingEnabled', true)
            ->loadView('documents.trainings-pdf', $this->data($person))
            ->setPaper('a4')
            ->output();
    }

    public function fileName(Person $person): string
    {
        return Str::slug(__('documents.trainings.proof.file', ['name' => $person->name])).'.pdf';
    }
}
