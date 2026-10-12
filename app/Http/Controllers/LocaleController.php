<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Language switch (Português, English, Español). Kept in the session and, for a signed-in person,
 * saved so it is used next time too.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(array_key_exists($locale, config('kiban.locales')), 404);

        $request->session()->put('locale', $locale);

        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['app_locale' => $locale])->save();
        }

        // Back to the same page, but never to another site (the previous address comes from the browser).
        $previous = (string) url()->previous();

        return redirect()->to(parse_url($previous, PHP_URL_HOST) === $request->getHost() ? $previous : '/');
    }
}
