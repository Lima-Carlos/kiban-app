<?php

namespace App\Modules\Pmoc\Services;

/**
 * The maintenance activities of an air-conditioning plan and how often each one is due, based on the usual PMOC
 * routine (Brazil: Ordinance 3.523/1998 and ABNT NBR 13971). A visit gets the activities of its level and of
 * every level below it: the yearly visit also does the half-yearly, quarterly and monthly ones.
 * They become the checklist of the visit's work order, filled in on the technician's phone.
 */
class PmocActivities
{
    /** Levels, in months: what is done every month, every 3, every 6 and once a year. */
    public const LEVELS = [1, 3, 6, 12];

    /**
     * key => [level in months, checklist type, unit]
     *
     * @var array<string, array{0: int, 1: string, 2?: string}>
     */
    public const ACTIVITIES = [
        'filters' => [1, 'check'],
        'drain_pan' => [1, 'check'],
        'drain' => [1, 'yes_no'],
        'noise' => [1, 'yes_no'],
        'controls' => [1, 'yes_no'],
        'supply_temperature' => [1, 'number', '°C'],
        'evaporator_coil' => [3, 'check'],
        'insulation' => [3, 'yes_no'],
        'supports' => [3, 'yes_no'],
        'voltage' => [3, 'number', 'V'],
        'current' => [3, 'number', 'A'],
        'condenser_coil' => [6, 'check'],
        'electrical' => [6, 'check'],
        'pressure' => [6, 'number', 'psi'],
        'chemical_cleaning' => [12, 'check'],
        'casing' => [12, 'yes_no'],
    ];

    /**
     * The activities due on a visit of this level (in the language of the current request).
     *
     * @return array<int, array{key: string, label: string, type: string, required: bool, unit: ?string, options: array<int, string>}>
     */
    public function checklist(int $level): array
    {
        $items = [];

        foreach (self::ACTIVITIES as $key => $activity) {
            if ($activity[0] > $level) {
                continue;
            }

            $items[] = [
                'key' => 'pmoc_'.$key,
                'label' => __('pmoc.activities.'.$key),
                'type' => $activity[1],
                'required' => in_array($activity[1], ['check', 'yes_no'], true),
                'unit' => $activity[2] ?? null,
                'options' => [],
            ];
        }

        foreach (['photo_before', 'photo_after'] as $photo) {
            $items[] = ['key' => 'pmoc_'.$photo, 'label' => __('pmoc.activities.'.$photo), 'type' => 'photo', 'required' => false, 'unit' => null, 'options' => []];
        }

        $items[] = ['key' => 'pmoc_notes', 'label' => __('pmoc.activities.notes'), 'type' => 'text', 'required' => false, 'unit' => null, 'options' => []];

        return $items;
    }

    /**
     * The level of a visit: the biggest period that closes on it. With visits every month, months 3, 6, 9 and 12
     * of the plan are quarterly, 6 and 12 half-yearly and 12 yearly.
     */
    public function level(int $monthsSinceStart, int $frequency): int
    {
        $closing = $monthsSinceStart + $frequency;

        foreach (array_reverse(self::LEVELS) as $level) {
            if ($level >= $frequency && $closing % $level === 0) {
                return $level;
            }
        }

        return $frequency;
    }

    /**
     * Activities with their frequency, for the PMOC document.
     *
     * @return array<int, array{label: string, level: int}>
     */
    public function schedule(): array
    {
        return collect(self::ACTIVITIES)->map(fn (array $activity, string $key): array => ['label' => __('pmoc.activities.'.$key), 'level' => $activity[0]])->values()->all();
    }
}
