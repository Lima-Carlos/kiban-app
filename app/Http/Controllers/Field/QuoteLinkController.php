<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Modules\Field\Enums\QuoteStatus;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\QuoteService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The customer's page of a quote (no login): the link is long and random, on the company's address. The customer
 * reads the lines and the total and approves (typing their name) or declines (with the reason, optional).
 * A draft is never shown; an answered or expired quote only shows its situation.
 */
class QuoteLinkController extends Controller
{
    public function show(string $token, QuoteService $quotes): View
    {
        $quote = $this->quote($token);
        $quotes->viewed($quote);

        return view('field.quote-public', $this->data($quote));
    }

    public function answer(Request $request, string $token, QuoteService $quotes): RedirectResponse|View
    {
        $quote = $this->quote($token);
        $origin = ['ip' => $request->ip(), 'device' => (string) $request->userAgent()];

        try {
            if ($request->input('answer') === 'approve') {
                $request->validate(['name' => ['required', 'string', 'max:120'], 'agree' => ['accepted']], [], [
                    'name' => __('field.quote_page.your_name'),
                    'agree' => __('field.quote_page.agree'),
                ]);
                $quotes->approve($quote, (string) $request->input('name'), $origin);
            } else {
                $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
                $quotes->decline($quote, $request->input('name'), $request->input('reason'), $origin);
            }
        } catch (FieldException $exception) {
            return view('field.quote-public', $this->data($quote->refresh()) + ['error' => $exception->getMessage()]);
        }

        return redirect()->route('field.quote', ['token' => $token]);
    }

    protected function quote(string $token): Quote
    {
        abort_unless(app(TenantContext::class)->get()->license()->hasModule(FieldModule::KEY), 404);

        $quote = Quote::query()->with(['items', 'customer', 'site', 'order'])->where('token', $token)->first();

        abort_if($quote === null || $quote->status === QuoteStatus::Draft, 404);

        return $quote;
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(Quote $quote): array
    {
        return [
            'quote' => $quote,
            'company' => app(TenantContext::class)->get()->name,
            'canAnswer' => $quote->status === QuoteStatus::Sent && ! $quote->isExpired(),
        ];
    }
}
