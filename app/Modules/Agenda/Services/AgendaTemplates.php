<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Package;
use App\Modules\Agenda\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Ready models per business, chosen in the first steps (like the Campo's FieldTemplates): the usual services with
 * the time and the price per size, the add-ons, the pick-up and a package. They are created in the language of
 * who chooses and become the company's own (rename, change or turn off anything). Choosing again only adds what
 * is missing. Prices are suggestions in reais, only for companies charging in BRL (elsewhere they stay empty).
 */
class AgendaTemplates
{
    /**
     * Pet shop: [minutes, price] per size (small, medium, large, xlarge); add-ons and the pick-up.
     *
     * @var array<string, array{minutes: int, price: int, sizes?: array<string, array{0: int, 1: int}>, addon?: bool, pickup?: bool, color: string}>
     */
    protected const PETSHOP = [
        'bath' => ['minutes' => 60, 'price' => 6500, 'color' => '#0369A1', 'sizes' => ['small' => [45, 5000], 'medium' => [60, 6500], 'large' => [90, 8500], 'xlarge' => [120, 11000]]],
        'bath_grooming' => ['minutes' => 120, 'price' => 11000, 'color' => '#7C3AED', 'sizes' => ['small' => [90, 9000], 'medium' => [120, 11000], 'large' => [150, 14000], 'xlarge' => [180, 18000]]],
        'hygienic_grooming' => ['minutes' => 30, 'price' => 3500, 'color' => '#0F766E', 'sizes' => ['small' => [30, 3000], 'medium' => [30, 3500], 'large' => [45, 4500], 'xlarge' => [45, 5500]]],
        'hydration' => ['minutes' => 20, 'price' => 3000, 'addon' => true, 'color' => '#15803D'],
        'nails' => ['minutes' => 10, 'price' => 1500, 'addon' => true, 'color' => '#A16207'],
        'teeth' => ['minutes' => 10, 'price' => 1500, 'addon' => true, 'color' => '#BE123C'],
        'pickup' => ['minutes' => 0, 'price' => 2000, 'addon' => true, 'pickup' => true, 'color' => '#B45309'],
    ];

    /**
     * @return array<string, string>
     */
    public function businesses(): array
    {
        return collect(AgendaSetting::BUSINESSES)->mapWithKeys(fn (string $business): array => [$business => __('agenda.templates.'.$business.'.name')])->all();
    }

    /**
     * Adds the ready services (and the "4 baths" package) of a business that the company does not have yet.
     *
     * @return array{services: int}
     */
    public function apply(string $business): array
    {
        if ($business !== 'petshop') {
            return ['services' => 0];
        }

        $withPrices = AgendaSetting::current()->currency === 'BRL';

        return DB::transaction(function () use ($withPrices): array {
            $added = 0;
            $sort = (int) Service::query()->max('sort');

            foreach (self::PETSHOP as $key => $model) {
                if (Service::query()->where('template_key', $key)->exists()) {
                    continue;
                }

                Service::create([
                    'template_key' => $key,
                    'name' => __('agenda.templates.petshop.services.'.$key),
                    'duration_minutes' => $model['minutes'],
                    'price_cents' => $withPrices ? $model['price'] : null,
                    'size_prices' => isset($model['sizes']) ? collect($model['sizes'])->map(fn (array $values): array => [
                        'duration_minutes' => $values[0],
                        'price_cents' => $withPrices ? $values[1] : null,
                    ])->all() : null,
                    'is_addon' => $model['addon'] ?? false,
                    'is_pickup' => $model['pickup'] ?? false,
                    'color' => $model['color'],
                    'sort' => ++$sort,
                ]);
                $added++;
            }

            $bath = Service::query()->where('template_key', 'bath')->first();

            if ($bath !== null && ! Package::query()->where('service_id', $bath->getKey())->exists()) {
                Package::create([
                    'name' => __('agenda.templates.petshop.package'),
                    'service_id' => $bath->getKey(),
                    'quantity' => 4,
                    'valid_days' => 45,
                    'price_cents' => $withPrices ? 22000 : null,
                ]);
            }

            return ['services' => $added];
        });
    }
}
