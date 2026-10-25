<?php

namespace App\Http\Controllers\TimeClock;

use App\Http\Controllers\Controller;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Services\ReceiptService;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The receipt of a punch at a signed address (no login): the page and the PDF. The address only works as it
 * was made (any change in it is refused), and only on the company's own address.
 */
class ReceiptController extends Controller
{
    public function show(int $punch, ReceiptService $receipts): View
    {
        $record = Punch::query()->findOrFail($punch);

        return view('timeclock.receipt', $receipts->data($record) + [
            'pdfUrl' => URL::signedRoute('timeclock.receipt.pdf', ['punch' => $record->getKey()], absolute: false),
        ]);
    }

    public function pdf(int $punch, ReceiptService $receipts): Response
    {
        $record = Punch::query()->findOrFail($punch);

        return response($receipts->pdf($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$receipts->fileName($record).'"',
        ]);
    }
}
