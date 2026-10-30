<?php

namespace App\Modules\Field\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A type of service of the company (e.g. "Installation", "No signal"), with the expected time, the price and
 * the technician's checklist: what is filled in on the phone at the customer's place. Each checklist item has a
 * fixed key, so the answers of a work order keep pointing to the right question even after it is renamed;
 * a work order copies the checklist when it is opened (part 3), so changing it here never changes old orders.
 */
class ServiceType extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    /** Kinds of checklist item: a tick, yes/no, a number (with unit), a text, one of a list, a photo. */
    public const ITEM_TYPES = ['check', 'yes_no', 'number', 'text', 'choice', 'photo'];

    protected $table = 'fd_service_types';

    protected $fillable = ['name', 'description', 'color', 'duration_minutes', 'price_cents', 'checklist', 'signature_required', 'photo_required', 'is_active', 'sort'];

    protected $attributes = ['checklist' => '[]', 'duration_minutes' => 60, 'signature_required' => true, 'photo_required' => false, 'is_active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'duration_minutes' => 'integer',
            'price_cents' => 'integer',
            'signature_required' => 'boolean',
            'photo_required' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ServiceType $type): void {
            $type->checklist = static::cleanChecklist((array) $type->checklist);
        });
    }

    /**
     * Only valid items, each with its key (new items get one), without empty options.
     *
     * @param  array<int|string, mixed>  $items
     * @return array<int, array{key: string, label: string, type: string, required: bool, unit: ?string, options: array<int, string>}>
     */
    public static function cleanChecklist(array $items): array
    {
        $keys = [];

        return collect($items)
            ->filter(fn ($item): bool => is_array($item) && filled($item['label'] ?? null))
            ->map(function (array $item) use (&$keys): array {
                $key = filled($item['key'] ?? null) && ! isset($keys[$item['key']]) ? (string) $item['key'] : Str::lower(Str::random(10));
                $keys[$key] = true;
                $type = in_array($item['type'] ?? null, self::ITEM_TYPES, true) ? $item['type'] : 'check';

                return [
                    'key' => $key,
                    'label' => trim((string) $item['label']),
                    'type' => $type,
                    'required' => (bool) ($item['required'] ?? false),
                    'unit' => $type === 'number' && filled($item['unit'] ?? null) ? trim((string) $item['unit']) : null,
                    'options' => $type === 'choice' ? collect((array) ($item['options'] ?? []))->map(fn ($option): string => trim((string) $option))->filter()->unique()->values()->all() : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Builder<ServiceType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
