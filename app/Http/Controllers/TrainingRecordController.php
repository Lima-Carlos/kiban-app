<?php

namespace App\Http\Controllers;

use App\People\Documents\TrainingRecord;
use App\People\Documents\TrainingService;
use App\People\Person;
use Illuminate\Http\Response;

/**
 * "Comprovante de cursos" (PDF) of a person: only for who takes care of the documents (owners, administrators,
 * HR) and the person themselves. Never through a public or shareable address: the manager sends the file.
 */
class TrainingRecordController extends Controller
{
    public function __invoke(int $person, TrainingService $trainings, TrainingRecord $record): Response
    {
        $owner = Person::query()->findOrFail($person);

        abort_unless($trainings->canGetProof(auth()->user(), $owner), 403);

        return response($record->pdf($owner), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$record->fileName($owner).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
