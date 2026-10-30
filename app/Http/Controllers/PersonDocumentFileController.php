<?php

namespace App\Http\Controllers;

use App\People\Documents\PersonDocument;
use App\People\Documents\PersonDocumentService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opens the file of a person's document (private disk) only for who may see it: owners, administrators, HR
 * and the person themselves. Never through a public or shareable address.
 */
class PersonDocumentFileController extends Controller
{
    public function __invoke(int $document, PersonDocumentService $documents): StreamedResponse
    {
        $record = PersonDocument::query()->with(['type', 'person'])->findOrFail($document);

        abort_unless($documents->canOpenFile(auth()->user(), $record), 403);
        abort_unless(Storage::disk('local')->exists($record->file_path), 404);

        return Storage::disk('local')->response($record->file_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
