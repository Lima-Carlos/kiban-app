<?php

namespace App\Http\Controllers;

use App\Connections\ConnectionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The window "your systems now work together": "Got it" hides it until tomorrow (or the next sign-in); with "Do
 * not show again", the connections shown never come back for this person in this company.
 */
class ConnectionsController extends Controller
{
    public function dismiss(Request $request, ConnectionCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'keys' => ['present', 'array', 'max:50'],
            'keys.*' => ['string', 'max:60'],
            'forever' => ['required', 'boolean'],
        ]);

        // Only what the window could show to this person.
        $keys = array_values(array_intersect($data['keys'], array_keys($catalog->relevant($request->user()))));
        $catalog->dismiss($request->user(), $keys, (bool) $data['forever']);

        return response()->json(['status' => 'ok']);
    }
}
