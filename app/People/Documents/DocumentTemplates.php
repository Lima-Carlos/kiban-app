<?php

namespace App\People\Documents;

use App\Modules\Field\FieldModule;
use App\Modules\Fleet\FleetModule;
use App\Support\Country;

/**
 * Ready document types by the company's country. Brazil: the driver's license (CNH), the periodic medical exam
 * (ASO, health data) and the usual safety trainings (NR). Other countries: generic ones (no Brazilian law).
 * They are created once, the first time the company opens its documents; then they are the company's own.
 */
class DocumentTemplates
{
    /**
     * key => validity in months (null = typed), health data, system it matters to.
     *
     * @var array<string, array{months: ?int, health?: bool, module?: string}>
     */
    public const BRAZIL = [
        'cnh' => ['months' => null, 'module' => FleetModule::KEY],
        'aso' => ['months' => 12, 'health' => true],
        'nr10' => ['months' => 24, 'module' => FieldModule::KEY],
        'nr35' => ['months' => 24, 'module' => FieldModule::KEY],
        'nr33' => ['months' => 12, 'module' => FieldModule::KEY],
        'nr11' => ['months' => null],
        'nr12' => ['months' => null],
        'nr06' => ['months' => null],
        'training' => ['months' => null],
        'other' => ['months' => null],
    ];

    /**
     * @var array<string, array{months: ?int, health?: bool, module?: string}>
     */
    public const OTHER_COUNTRIES = [
        'driver_license' => ['months' => null, 'module' => FleetModule::KEY],
        'medical_exam' => ['months' => 12, 'health' => true],
        'safety_training' => ['months' => null, 'module' => FieldModule::KEY],
        'other' => ['months' => null],
    ];

    /** Keys of the driver's license (kept in step with the Frota driver record). */
    public const DRIVER_LICENSE = ['cnh', 'driver_license'];

    /**
     * @return array<string, array{months: ?int, health?: bool, module?: string}>
     */
    public static function forCountry(?string $country = null): array
    {
        return Country::isBrazil($country) ? self::BRAZIL : self::OTHER_COUNTRIES;
    }

    /** Creates the ready types of the company's country, once. Returns how many were created. */
    public static function ensure(): int
    {
        if (DocumentType::query()->exists()) {
            return 0;
        }

        $sort = 0;

        foreach (static::forCountry() as $key => $template) {
            DocumentType::query()->create([
                'key' => $key,
                'validity_months' => $template['months'],
                'alert_days' => 30,
                'is_health' => $template['health'] ?? false,
                'module' => $template['module'] ?? null,
                'sort' => $sort += 10,
            ]);
        }

        return $sort / 10;
    }
}
