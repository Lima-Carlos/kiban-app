<?php

namespace App\Connections\Bridges;

use App\Modules\Field\Models\ServiceType;
use App\People\Epi\EpiDeliveryLine;
use App\People\Epi\EpiItem;
use App\People\Epi\EpiService;
use App\People\Person;
use Illuminate\Database\Eloquent\Builder;

/**
 * Campo + PPE (People): a type of service may say which PPE it needs (e.g. work at height → harness). A
 * technician who does not have one of them (no delivery still with them) gets a warning when the order is
 * scheduled and when it is started. Only a warning: the schedule and the work are never blocked.
 *
 * Sizes count as the same item: "Safety boots · 40" covers "Safety boots · 42" (same name).
 */
class FieldEpi
{
    public function __construct(protected EpiService $epi) {}

    /**
     * Names of the needed PPE the technician (login) does not have.
     *
     * @return array<int, string>
     */
    public function missing(int|string|null $technicianId, ServiceType|int|string|null $type): array
    {
        if (blank($technicianId) || $type === null || ! $this->epi->enabled()) {
            return [];
        }

        $type = $type instanceof ServiceType ? $type : ServiceType::query()->find($type);
        $required = array_filter(array_map('intval', (array) ($type?->required_epi ?? [])));

        if ($required === []) {
            return [];
        }

        $items = EpiItem::query()->whereKey($required)->active()->get();
        $person = Person::query()->where('user_id', $technicianId)->first();

        if ($items->isEmpty()) {
            return [];
        }

        // Names the technician has now (any size of the same item counts).
        $has = $person === null ? collect() : EpiDeliveryLine::query()->open()
            ->whereHas('delivery', fn (Builder $query) => $query->where('person_id', $person->getKey()))
            ->with('item')->get()
            ->flatMap(fn (EpiDeliveryLine $line): array => [mb_strtolower($line->item_name), mb_strtolower((string) $line->item?->label())])
            ->unique();

        return $items
            ->reject(fn (EpiItem $item): bool => $has->contains(mb_strtolower($item->label())))
            ->map(fn (EpiItem $item): string => $item->label())
            ->unique()
            ->values()
            ->all();
    }

    /** "Atenção: sem EPI registrado — Cinto paraquedista. O agendamento continua permitido." or null. */
    public function warning(int|string|null $technicianId, ServiceType|int|string|null $type): ?string
    {
        $missing = $this->missing($technicianId, $type);

        return $missing === [] ? null : __('epi.field.missing', ['items' => implode(', ', $missing)]);
    }

    /** On the technician's phone, when the work starts: "This service needs PPE not registered with you: ...". */
    public function technicianNotice(int|string|null $technicianId, ServiceType|int|string|null $type): ?string
    {
        $missing = $this->missing($technicianId, $type);

        return $missing === [] ? null : __('epi.field.missing_technician', ['items' => implode(', ', $missing)]);
    }
}
