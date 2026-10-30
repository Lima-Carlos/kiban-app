<?php

namespace App\Modules\Field\Services;

use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Models\ServiceType;
use Illuminate\Support\Facades\DB;

/**
 * Ready models per business, chosen in the first steps: the usual types of service (with time and the
 * technician's checklist) and the usual materials. They are created in the language of who chooses and become
 * the company's own (it renames, changes or turns off anything). Choosing again only adds what is missing.
 */
class FieldTemplates
{
    /**
     * Checklist items: type, unit (numbers), options (choices) and whether they are required.
     *
     * @var array<string, array{type: string, unit?: string, options?: string, required?: bool}>
     */
    protected const ITEMS = [
        'signal_power' => ['type' => 'number', 'unit' => 'dBm', 'required' => true],
        'onu_serial' => ['type' => 'text', 'required' => true],
        'cto_photo' => ['type' => 'photo', 'required' => true],
        'speed_test' => ['type' => 'number', 'unit' => 'Mbps', 'required' => true],
        'wifi_explained' => ['type' => 'check'],
        'place_clean' => ['type' => 'check'],
        'problem_found' => ['type' => 'text', 'required' => true],
        'cause' => ['type' => 'choice', 'options' => 'cause', 'required' => true],
        'solution' => ['type' => 'text', 'required' => true],
        'photo_before' => ['type' => 'photo'],
        'photo_after' => ['type' => 'photo'],
        'equipment_returned' => ['type' => 'yes_no', 'required' => true],
        'photo_equipment' => ['type' => 'photo'],
        'btus' => ['type' => 'number', 'unit' => 'BTU', 'required' => true],
        'pipe_length' => ['type' => 'number', 'unit' => 'm'],
        'vacuum_done' => ['type' => 'yes_no', 'required' => true],
        'pressure' => ['type' => 'number', 'unit' => 'psi'],
        'outlet_temp' => ['type' => 'number', 'unit' => '°C'],
        'drain_tested' => ['type' => 'check'],
        'filters_cleaned' => ['type' => 'check', 'required' => true],
        'coil_cleaned' => ['type' => 'check'],
        'gas_type' => ['type' => 'choice', 'options' => 'gas', 'required' => true],
        'gas_amount' => ['type' => 'number', 'unit' => 'kg', 'required' => true],
        'cameras_count' => ['type' => 'number', 'required' => true],
        'recorder_serial' => ['type' => 'text'],
        'app_configured' => ['type' => 'yes_no', 'required' => true],
        'photo_cameras' => ['type' => 'photo'],
        'zones_count' => ['type' => 'number'],
        'panel_serial' => ['type' => 'text'],
        'system_tested' => ['type' => 'yes_no', 'required' => true],
        'customer_trained' => ['type' => 'check'],
        'items_checked' => ['type' => 'check', 'required' => true],
        'observations' => ['type' => 'text'],
        'working_at_end' => ['type' => 'yes_no', 'required' => true],
    ];

    /** Options of the choice items. */
    protected const OPTIONS = [
        'cause' => ['fiber_break', 'connector', 'equipment', 'customer_side', 'other'],
        'gas' => ['r410a', 'r32', 'r22'],
    ];

    /**
     * Per business: types of service (minutes, checklist) and materials (unit).
     *
     * @var array<string, array{types: array<string, array{minutes: int, items: array<int, string>}>, materials: array<string, string>}>
     */
    protected const MODELS = [
        'isp' => [
            'types' => [
                'install' => ['minutes' => 120, 'items' => ['signal_power', 'onu_serial', 'cto_photo', 'speed_test', 'wifi_explained', 'place_clean']],
                'no_signal' => ['minutes' => 60, 'items' => ['problem_found', 'cause', 'signal_power', 'speed_test', 'photo_after']],
                'move' => ['minutes' => 120, 'items' => ['signal_power', 'onu_serial', 'speed_test', 'photo_after']],
                'removal' => ['minutes' => 30, 'items' => ['equipment_returned', 'onu_serial', 'photo_equipment']],
                'visit' => ['minutes' => 60, 'items' => ['problem_found', 'solution', 'photo_after']],
            ],
            'materials' => ['drop_cable' => 'm', 'fiber_connector' => 'un', 'onu' => 'un', 'wifi_router' => 'un', 'anchor' => 'un', 'patch_cord' => 'un'],
        ],
        'hvac' => [
            'types' => [
                'split_install' => ['minutes' => 180, 'items' => ['btus', 'pipe_length', 'vacuum_done', 'pressure', 'outlet_temp', 'drain_tested', 'photo_before', 'photo_after']],
                'cleaning' => ['minutes' => 60, 'items' => ['filters_cleaned', 'coil_cleaned', 'outlet_temp', 'photo_before', 'photo_after']],
                'corrective' => ['minutes' => 90, 'items' => ['problem_found', 'solution', 'pressure', 'outlet_temp', 'photo_after']],
                'gas_charge' => ['minutes' => 60, 'items' => ['gas_type', 'gas_amount', 'pressure', 'outlet_temp']],
            ],
            'materials' => ['copper_pipe' => 'm', 'gas_r410a' => 'kg', 'gas_r32' => 'kg', 'wall_bracket' => 'un', 'drain_hose' => 'm', 'pipe_insulation' => 'm'],
        ],
        'security' => [
            'types' => [
                'cctv_install' => ['minutes' => 240, 'items' => ['cameras_count', 'recorder_serial', 'app_configured', 'photo_cameras', 'customer_trained']],
                'alarm_install' => ['minutes' => 180, 'items' => ['zones_count', 'panel_serial', 'system_tested', 'customer_trained', 'photo_after']],
                'maintenance' => ['minutes' => 60, 'items' => ['problem_found', 'solution', 'system_tested', 'photo_after']],
                'visit' => ['minutes' => 60, 'items' => ['problem_found', 'solution', 'photo_after']],
            ],
            'materials' => ['camera' => 'un', 'recorder' => 'un', 'hard_drive' => 'un', 'utp_cable' => 'm', 'power_supply' => 'un', 'sensor' => 'un', 'siren' => 'un'],
        ],
        'general' => [
            'types' => [
                'visit' => ['minutes' => 60, 'items' => ['problem_found', 'solution', 'photo_before', 'photo_after']],
                'preventive' => ['minutes' => 90, 'items' => ['items_checked', 'observations', 'photo_after']],
                'corrective' => ['minutes' => 90, 'items' => ['problem_found', 'solution', 'photo_before', 'photo_after']],
                'install' => ['minutes' => 120, 'items' => ['working_at_end', 'customer_trained', 'photo_after']],
            ],
            'materials' => ['spare_part' => 'un', 'screws' => 'un', 'electric_cable' => 'm'],
        ],
    ];

    /** Colors of the types of service on the agenda, in order. */
    protected const COLORS = ['#044299', '#0F766E', '#B45309', '#7C3AED', '#BE123C', '#15803D'];

    /**
     * Businesses with a ready model, for the choice on the settings.
     *
     * @return array<string, string>
     */
    public function businesses(): array
    {
        return collect(FieldSetting::BUSINESSES)->mapWithKeys(fn (string $business): array => [$business => __('field.templates.'.$business.'.name')])->all();
    }

    /**
     * Creates the ready model of the business (what is missing only). Returns how many types and materials were added.
     *
     * @return array{types: int, materials: int}
     */
    public function apply(string $business): array
    {
        $model = self::MODELS[$business] ?? null;

        if ($model === null) {
            return ['types' => 0, 'materials' => 0];
        }

        return DB::transaction(function () use ($business, $model): array {
            $added = ['types' => 0, 'materials' => 0];
            $signature = FieldSetting::current()->signature_required;
            $sort = (int) ServiceType::query()->max('sort');

            foreach ($model['types'] as $key => $type) {
                $name = __("field.templates.{$business}.types.{$key}");

                if (ServiceType::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                    continue;
                }

                ServiceType::create([
                    'name' => $name,
                    'description' => __("field.templates.{$business}.types.{$key}_help"),
                    'color' => self::COLORS[$added['types'] % count(self::COLORS)],
                    'duration_minutes' => $type['minutes'],
                    'checklist' => array_map(fn (string $item): array => $this->item($item), $type['items']),
                    'signature_required' => $signature,
                    'photo_required' => false,
                    'sort' => ++$sort,
                ]);
                $added['types']++;
            }

            foreach ($model['materials'] as $key => $unit) {
                $name = __("field.templates.materials.{$key}");

                if (Material::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                    continue;
                }

                Material::create(['name' => $name, 'unit' => $unit]);
                $added['materials']++;
            }

            return $added;
        });
    }

    /**
     * A checklist item of the model, in the language of who applies it. The key is the item's own (stable).
     *
     * @return array{key: string, label: string, type: string, required: bool, unit: ?string, options: array<int, string>}
     */
    protected function item(string $key): array
    {
        $item = self::ITEMS[$key];

        return [
            'key' => $key,
            'label' => __("field.templates.items.{$key}"),
            'type' => $item['type'],
            'required' => $item['required'] ?? false,
            'unit' => $item['unit'] ?? null,
            'options' => isset($item['options']) ? array_map(fn (string $option): string => __("field.templates.options.{$item['options']}.{$option}"), self::OPTIONS[$item['options']]) : [],
        ];
    }
}
