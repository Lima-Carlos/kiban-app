<?php

namespace App\Http\Controllers\TimeClock;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Modules\TimeClock\Models\Closing;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Modules\TimeClock\Services\TimesheetDocument;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The time sheet of the month at a long random link (no login), for who has no login (e.g. only clocks in on the
 * tablet): the employee checks the days, downloads the PDF and signs it or says they do not agree. The tablet PIN
 * (or, without one, the last 4 digits of the document) confirms it is them; few wrong tries are allowed.
 */
class SignController extends Controller
{
    public const MAX_TRIES = 5;

    public function show(string $token, MonthClosing $closings): View
    {
        $closing = $this->find($token);
        $employee = $closing->employee;
        $profile = CompanyProfile::current();

        return view('timeclock.sign', [
            'closing' => $closing,
            'employee' => $employee,
            'company' => $profile->name(),
            'logo' => $profile->logoDataUrl(),
            'color' => $profile->color(),
            'monthLabel' => $closings->monthLabel($closing->month),
            'totals' => (array) $closing->totals,
            'days' => $closing->reopened_at === null ? $closings->days($closing->setRelation('employee', $employee)) : [],
            'identity' => $closings->identityCheck($closing),
        ]);
    }

    public function pdf(string $token, TimesheetDocument $documents): Response
    {
        $closing = $this->find($token);
        abort_if($closing->reopened_at !== null, 404);

        return response($documents->pdf($closing->employee, $closing->month), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documents->fileName($closing->employee, $closing->month).'"',
        ]);
    }

    public function answer(string $token, Request $request, MonthClosing $closings): RedirectResponse
    {
        $closing = $this->find($token);
        $sign = $request->input('answer') === 'sign';

        $request->validate([
            'answer' => ['required', 'in:sign,dispute'],
            'agree' => $sign ? ['accepted'] : ['nullable'],
            'reason' => $sign ? ['nullable'] : ['required', 'string', 'max:2000'],
            'identity' => $closings->identityCheck($closing) !== null ? ['required', 'string', 'max:20'] : ['nullable'],
        ], [], [
            'reason' => __('timeclock.closing.dispute_reason'),
            'identity' => __('timeclock.sign_link.identity_'.($closings->identityCheck($closing) ?? 'pin')),
            'agree' => __('timeclock.closing.sign_agree'),
        ]);

        $key = 'timeclock-sign:'.$closing->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_TRIES)) {
            return back()->withErrors(['identity' => __('timeclock.sign_link.too_many')]);
        }

        if (! $closings->identityMatches($closing, (string) $request->input('identity'))) {
            RateLimiter::hit($key, 15 * 60);

            return back()->withInput($request->except('identity'))->withErrors(['identity' => __('timeclock.sign_link.identity_wrong')]);
        }

        RateLimiter::clear($key);

        try {
            $sign
                ? $closings->signByLink($closing, $request->ip(), $request->userAgent())
                : $closings->disputeByLink($closing, (string) $request->input('reason'));
        } catch (PersonRuleException $exception) {
            return back()->withErrors(['answer' => $exception->getMessage()]);
        }

        return redirect()->route('timeclock.sign', ['token' => $token])->with('status', __($sign ? 'timeclock.closing.signed' : 'timeclock.closing.disputed'));
    }

    protected function find(string $token): Closing
    {
        abort_unless(app(TenantContext::class)->get()->license()->hasModule(TimeClockModule::KEY), 404);

        return Closing::query()->with(['employee.person', 'employee.workplace'])->where('sign_token', $token)->firstOrFail();
    }
}
