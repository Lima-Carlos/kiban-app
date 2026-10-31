<?php

namespace App\People\Epi;

use App\Support\Country;

/**
 * Ready items by the company's country, for teams in the street (internet providers, installers, air
 * conditioning, security systems, maintenance). Brazil: the usual PPE (with a field for the CA) and tools.
 * Other countries: a generic list (no Brazilian law). Created once, the first time the company opens its PPE;
 * then they are the company's own (it renames, changes, turns off or adds items).
 */
class EpiTemplates
{
    /**
     * key => kind, useful life [amount, unit] (null = no automatic replacement), goes back to the stock.
     *
     * @var array<string, array{kind: string, life: ?array{0: int, 1: string}, reusable?: bool}>
     */
    public const BRAZIL = [
        'helmet' => ['kind' => 'epi', 'life' => [24, 'months']],
        'glasses' => ['kind' => 'epi', 'life' => [6, 'months']],
        'insulating_gloves' => ['kind' => 'epi', 'life' => [6, 'months']],
        'leather_gloves' => ['kind' => 'epi', 'life' => [30, 'days']],
        'harness' => ['kind' => 'epi', 'life' => [24, 'months']],
        'safety_boots' => ['kind' => 'epi', 'life' => [6, 'months']],
        'ear_protection' => ['kind' => 'epi', 'life' => [30, 'days']],
        'uniform' => ['kind' => 'tool', 'life' => [6, 'months']],
        'pliers' => ['kind' => 'tool', 'life' => null, 'reusable' => true],
        'ladder' => ['kind' => 'tool', 'life' => null, 'reusable' => true],
        'multimeter' => ['kind' => 'tool', 'life' => null, 'reusable' => true],
        'drill' => ['kind' => 'tool', 'life' => null, 'reusable' => true],
    ];

    /**
     * @var array<string, array{kind: string, life: ?array{0: int, 1: string}, reusable?: bool}>
     */
    public const OTHER_COUNTRIES = [
        'safety_helmet' => ['kind' => 'epi', 'life' => [24, 'months']],
        'safety_glasses' => ['kind' => 'epi', 'life' => [6, 'months']],
        'work_gloves' => ['kind' => 'epi', 'life' => [30, 'days']],
        'fall_harness' => ['kind' => 'epi', 'life' => [24, 'months']],
        'work_boots' => ['kind' => 'epi', 'life' => [6, 'months']],
        'hearing_protection' => ['kind' => 'epi', 'life' => [30, 'days']],
        'uniform' => ['kind' => 'tool', 'life' => [6, 'months']],
        'pliers' => ['kind' => 'tool', 'life' => null, 'reusable' => true],
        'ladder' => ['kind' => 'tool', 'life' => null, 'reusable' => true],
        'multimeter' => ['kind' => 'tool', 'life' => null, 'reusable' => true],
        'drill' => ['kind' => 'tool', 'life' => null, 'reusable' => true],
    ];

    /**
     * @return array<string, array{kind: string, life: ?array{0: int, 1: string}, reusable?: bool}>
     */
    public static function forCountry(?string $country = null): array
    {
        return Country::isBrazil($country) ? self::BRAZIL : self::OTHER_COUNTRIES;
    }

    /** Creates the ready items of the company's country, once. Returns how many were created. */
    public static function ensure(): int
    {
        if (EpiItem::query()->exists()) {
            return 0;
        }

        $sort = 0;

        foreach (static::forCountry() as $key => $template) {
            EpiItem::query()->create([
                'key' => $key,
                'kind' => $template['kind'],
                'life_amount' => $template['life'][0] ?? null,
                'life_unit' => $template['life'][1] ?? 'months',
                'reusable' => $template['reusable'] ?? false,
                'sort' => $sort += 10,
            ]);
        }

        return $sort / 10;
    }
}
