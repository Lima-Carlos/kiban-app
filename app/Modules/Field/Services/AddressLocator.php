<?php

namespace App\Modules\Field\Services;

use App\Modules\Field\Models\Site;
use App\Support\Country;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Addresses and the map, with free services:
 * - Brazilian postal code (CEP) → street, district, city and state (ViaCEP), to fill the form;
 * - address → point on the map (OpenStreetMap/Nominatim), in the background, at most one search per second
 *   (their rule). The point found is approximate; the technician confirms it on the first visit.
 * Without internet or with the service down, nothing breaks: the address waits for the next try.
 */
class AddressLocator
{
    public const VIACEP = 'https://viacep.com.br/ws/%s/json/';

    public const NOMINATIM = 'https://nominatim.openstreetmap.org/search';

    /**
     * @return array{street: ?string, district: ?string, city: ?string, state: ?string}|null
     */
    public function postalCode(?string $postalCode): ?array
    {
        $digits = (string) preg_replace('/\D/', '', (string) $postalCode);

        if (strlen($digits) !== 8) {
            return null;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get(sprintf(self::VIACEP, $digits));
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->ok() || $response->json('erro')) {
            return null;
        }

        return [
            'street' => $response->json('logradouro') ?: null,
            'district' => $response->json('bairro') ?: null,
            'city' => $response->json('localidade') ?: null,
            'state' => $response->json('uf') ?: null,
        ];
    }

    public function enabled(): bool
    {
        return config('kiban.field.locator') === 'openstreetmap';
    }

    /**
     * Searches the site's address on the map and keeps the point (approximate). Returns true when found.
     * A point typed or confirmed by a person is never replaced.
     */
    public function locate(Site $site): bool
    {
        if ($site->hasPoint()) {
            $site->forceFill(['locate_status' => null])->save();

            return true;
        }

        $country = strtolower($site->country ?: Country::current());
        $place = null;

        // From the most precise to the least: street with number, street only, then the district.
        $attempts = array_values(array_filter([
            filled($site->number) ? ['street' => $site->number.' '.$site->street, 'city' => $site->city, 'state' => $site->state] : null,
            ['street' => $site->street, 'city' => $site->city, 'state' => $site->state],
            filled($site->district) ? ['q' => collect([$site->district, $site->city, $site->state])->filter()->implode(', ')] : null,
        ]));

        foreach ($attempts as $index => $query) {
            if ($index > 0 && ($pause = (int) config('kiban.field.locator_pause_ms')) > 0) {
                usleep($pause * 1000);
            }

            try {
                $response = Http::timeout(15)
                    ->withHeaders(['User-Agent' => 'KibanCampo/1.0 ('.config('kiban.field.locator_contact').')'])
                    ->acceptJson()
                    ->get(self::NOMINATIM, array_filter($query) + ['countrycodes' => $country, 'format' => 'jsonv2', 'limit' => 1]);
            } catch (ConnectionException) {
                return false;
            }

            // Service down or refusing: try again in a later run.
            if (! $response->ok()) {
                return false;
            }

            $place = $response->json('0');

            if (is_array($place) && isset($place['lat'], $place['lon'])) {
                break;
            }

            $place = null;
        }

        $found = $place !== null;

        $site->forceFill($found
            ? ['latitude' => round((float) $place['lat'], 6), 'longitude' => round((float) $place['lon'], 6), 'location_source' => Site::SEARCH, 'locate_status' => Site::FOUND, 'located_at' => now()]
            : ['locate_status' => Site::NOT_FOUND, 'located_at' => now()]
        )->save();

        return $found;
    }
}
