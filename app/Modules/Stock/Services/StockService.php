<?php

namespace App\Modules\Stock\Services;

use App\Models\User;
use App\Modules\Field\Models\OrderMaterial;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Stock\Models\Balance;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Models\Movement;
use App\Modules\Stock\Models\Unit;
use App\Modules\Stock\StockModule;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every rule of the stock: entries, transfers between places (warehouse ↔ technician), use on a Campo work order
 * (from the technician's own stock, by itself), counts (adjustments) and the units tracked by serial number,
 * lent to customers (comodato), returned, defective or lost. Every change is a Movement; balances never change
 * any other way.
 */
class StockService
{
    public function __construct(protected TenantContext $context) {}

    public function balance(Item $item, Location $location): float
    {
        return (float) (Balance::query()->where('item_id', $item->getKey())->where('location_id', $location->getKey())->value('quantity') ?? 0);
    }

    /** Items bought or received into a place. */
    public function receive(Item $item, Location $location, float $quantity, ?string $note = null, ?User $by = null): Movement
    {
        $this->positive($quantity);
        $this->notSerialized($item);

        return DB::transaction(function () use ($item, $location, $quantity, $note, $by): Movement {
            $this->change($item, $location, $quantity);

            return $this->record($item, 'entry', $quantity, null, $location, $by, ['note' => $note]);
        });
    }

    /**
     * The entry of a whole invoice (NF-e): each checked line goes into the place, into the chosen item or a new one
     * created with the invoice's code, description and unit. The note keeps the number, supplier and access key,
     * so the same invoice is not entered twice.
     *
     * @param  array{number: string, supplier: string, key: string}  $invoice
     * @param  array<int, array{code: string, description: string, unit: string, quantity: float|string, item_id: int|string|null}>  $lines
     * @return int how many lines went in
     */
    public function receiveInvoice(array $invoice, array $lines, Location $location, ?User $by = null): int
    {
        if ($invoice['key'] !== '' && Movement::query()->where('note', 'like', '%'.$invoice['key'].'%')->exists()) {
            throw new StockException(__('stock.invoice.errors.already'));
        }

        $note = __('stock.invoice.note', ['number' => $invoice['number'], 'supplier' => $invoice['supplier'], 'key' => $invoice['key']]);

        return DB::transaction(function () use ($lines, $location, $note, $by): int {
            $count = 0;

            foreach ($lines as $line) {
                $quantity = (float) $line['quantity'];

                if ($quantity <= 0 || blank($line['item_id'] ?? null) || $line['item_id'] === 'skip') {
                    continue;
                }

                $item = $line['item_id'] === 'new'
                    ? Item::query()->create(['name' => $line['description'], 'sku' => $line['code'] ?: null, 'unit' => $line['unit'] ?: 'un'])
                    : Item::query()->findOrFail($line['item_id']);

                if ($item->sku === null && filled($line['code'])) {
                    $item->update(['sku' => $line['code']]);
                }

                $this->receive($item, $location, $quantity, mb_substr($note, 0, 255), $by);
                $count++;
            }

            if ($count === 0) {
                throw new StockException(__('stock.invoice.errors.nothing'));
            }

            return $count;
        });
    }

    /** From one place to another (e.g. the warehouse fills a technician's vehicle). Never more than there is. */
    public function transfer(Item $item, Location $from, Location $to, float $quantity, ?User $by = null): Movement
    {
        $this->positive($quantity);
        $this->notSerialized($item);

        if ($from->is($to)) {
            throw new StockException(__('stock.errors.same_place'));
        }

        return DB::transaction(function () use ($item, $from, $to, $quantity, $by): Movement {
            $this->enough($item, $from, $quantity);
            $this->change($item, $from, -$quantity);
            $this->change($item, $to, $quantity);

            return $this->record($item, 'transfer', $quantity, $from, $to, $by);
        });
    }

    /** The real count of a place: the difference is recorded as an adjustment. */
    public function adjust(Item $item, Location $location, float $counted, ?string $note = null, ?User $by = null): ?Movement
    {
        $this->notSerialized($item);

        if ($counted < 0) {
            throw new StockException(__('stock.errors.quantity'));
        }

        return DB::transaction(function () use ($item, $location, $counted, $note, $by): ?Movement {
            $difference = round($counted - $this->balance($item, $location), 3);

            if ($difference == 0.0) {
                return null;
            }

            $this->change($item, $location, $difference);

            return $this->record($item, 'adjust', $difference, $difference < 0 ? $location : null, $difference > 0 ? $location : null, $by, ['note' => $note]);
        });
    }

    /** Used up (manually or by a work order). A work order may take the balance below zero: it was used anyway. */
    public function consume(Item $item, Location $location, float $quantity, ?WorkOrder $order = null, ?OrderMaterial $material = null, ?User $by = null, bool $allowNegative = false): Movement
    {
        $this->positive($quantity);

        return DB::transaction(function () use ($item, $location, $quantity, $order, $material, $by, $allowNegative): Movement {
            if (! $allowNegative) {
                $this->enough($item, $location, $quantity);
            }

            $this->change($item, $location, -$quantity);

            return $this->record($item, 'use', $quantity, $location, null, $by, ['work_order_id' => $order?->getKey(), 'order_material_id' => $material?->getKey()]);
        });
    }

    /**
     * Campo: a material written down on a work order leaves the stock of the order's technician, when the company
     * has the Estoque, the material is one of its items and the technician has a place. Nothing happens otherwise.
     */
    public function fromFieldMaterial(OrderMaterial $material): ?Movement
    {
        if (! $this->enabled() || $material->material_id === null || (float) $material->quantity <= 0) {
            return null;
        }

        $item = Item::query()->active()->where('material_id', $material->material_id)->where('serialized', false)->first();
        $order = WorkOrder::query()->find($material->order_id);
        $location = $order?->technician_id ? Location::query()->active()->where('kind', Location::TECHNICIAN)->where('user_id', $order->technician_id)->first() : null;

        if ($item === null || $location === null) {
            return null;
        }

        return $this->consume($item, $location, (float) $material->quantity, $order, $material, null, true);
    }

    /** Campo: a material removed from a work order goes back to where it left. */
    public function undoFieldMaterial(OrderMaterial $material): ?Movement
    {
        if (! $this->enabled()) {
            return null;
        }

        $used = Movement::query()->where('kind', 'use')->where('order_material_id', $material->getKey())->first();

        if ($used === null || $used->from_location_id === null) {
            return null;
        }

        return DB::transaction(function () use ($used): Movement {
            $location = Location::query()->findOrFail($used->from_location_id);
            $this->change($used->item, $location, (float) $used->quantity);
            $used->forceFill(['order_material_id' => null])->save();

            return $this->record($used->item, 'return', (float) $used->quantity, null, $location, null, ['work_order_id' => $used->work_order_id, 'note' => __('stock.movements.undone')]);
        });
    }

    /**
     * New serialized units arriving in a place (one per serial number). Serials already registered are skipped.
     *
     * @param  array<int, string>  $serials
     * @return Collection<int, Unit>
     */
    public function addUnits(Item $item, Location $location, array $serials, ?User $by = null): Collection
    {
        if (! $item->serialized) {
            throw new StockException(__('stock.errors.not_serialized'));
        }

        $serials = collect($serials)->map(fn ($serial): string => mb_strtoupper(trim((string) $serial)))->filter()->unique()->values();
        $known = Unit::query()->where('item_id', $item->getKey())->whereIn('serial', $serials)->pluck('serial')->all();

        return DB::transaction(function () use ($item, $location, $serials, $known, $by): Collection {
            return $serials->reject(fn (string $serial): bool => in_array($serial, $known, true))->map(function (string $serial) use ($item, $location, $by): Unit {
                $unit = Unit::query()->create(['item_id' => $item->getKey(), 'serial' => mb_substr($serial, 0, 80), 'status' => Unit::IN_STOCK, 'location_id' => $location->getKey(), 'since_at' => now()]);
                $this->change($item, $location, 1);
                $this->record($item, 'entry', 1, null, $location, $by, ['unit_id' => $unit->getKey()]);

                return $unit;
            })->values();
        });
    }

    /** A unit goes from a place to another (e.g. to the technician who will install it). */
    public function moveUnit(Unit $unit, Location $to, ?User $by = null): Movement
    {
        $this->inStock($unit);

        if ($unit->location_id === $to->getKey()) {
            throw new StockException(__('stock.errors.same_place'));
        }

        return DB::transaction(function () use ($unit, $to, $by): Movement {
            $from = $unit->location;
            $this->change($unit->item, $from, -1);
            $this->change($unit->item, $to, 1);
            $unit->forceFill(['location_id' => $to->getKey(), 'since_at' => now()])->save();

            return $this->record($unit->item, 'transfer', 1, $from, $to, $by, ['unit_id' => $unit->getKey()]);
        });
    }

    /** Lent to a customer (comodato): leaves the place where it was; the customer is a Campo customer or a name. */
    public function lend(Unit $unit, ?int $customerId, ?string $customerName, ?WorkOrder $order = null, ?User $by = null): Movement
    {
        $this->inStock($unit);
        $customerName = filled($customerName) ? trim((string) $customerName) : null;

        if ($customerId === null && $customerName === null) {
            throw new StockException(__('stock.errors.customer'));
        }

        return DB::transaction(function () use ($unit, $customerId, $customerName, $order, $by): Movement {
            $from = $unit->location;
            $this->change($unit->item, $from, -1);
            $unit->forceFill(['status' => Unit::WITH_CUSTOMER, 'location_id' => null, 'customer_id' => $customerId, 'customer_name' => $customerName, 'since_at' => now()])->save();

            return $this->record($unit->item, 'lend', 1, $from, null, $by, ['unit_id' => $unit->getKey(), 'work_order_id' => $order?->getKey(), 'customer_name' => $unit->customer?->displayName() ?? $customerName]);
        });
    }

    /** Back from the customer (or from repair) into a place. */
    public function giveBack(Unit $unit, Location $to, ?User $by = null): Movement
    {
        if ($unit->status === Unit::IN_STOCK) {
            throw new StockException(__('stock.errors.already_in_stock'));
        }

        return DB::transaction(function () use ($unit, $to, $by): Movement {
            $customer = $unit->customer?->displayName() ?? $unit->customer_name;
            $this->change($unit->item, $to, 1);
            $unit->forceFill(['status' => Unit::IN_STOCK, 'location_id' => $to->getKey(), 'customer_id' => null, 'customer_name' => null, 'since_at' => now()])->save();

            return $this->record($unit->item, 'return', 1, null, $to, $by, ['unit_id' => $unit->getKey(), 'customer_name' => $customer]);
        });
    }

    /** Defective or lost: it stops counting in the place where it was. */
    public function writeOff(Unit $unit, string $status, ?string $note = null, ?User $by = null): Movement
    {
        if (! in_array($status, [Unit::DEFECTIVE, Unit::LOST], true) || $unit->status === $status) {
            throw new StockException(__('stock.errors.status'));
        }

        return DB::transaction(function () use ($unit, $status, $note, $by): Movement {
            $from = $unit->status === Unit::IN_STOCK ? $unit->location : null;

            if ($from !== null) {
                $this->change($unit->item, $from, -1);
            }

            $customer = $unit->customer?->displayName() ?? $unit->customer_name;
            $unit->forceFill(['status' => $status, 'location_id' => null, 'since_at' => now()])->save();

            return $this->record($unit->item, 'loss', 1, $from, null, $by, ['unit_id' => $unit->getKey(), 'customer_name' => $customer, 'note' => $note ?? __('stock.units.statuses.'.$status)]);
        });
    }

    /**
     * Items below their minimum (all places together).
     *
     * @return Collection<int, array{item: Item, total: float}>
     */
    public function lowStock(): Collection
    {
        return Item::query()->active()->where('min_quantity', '>', 0)->withSum('balances', 'quantity')->orderBy('name')->get()
            ->filter(fn (Item $item): bool => (float) $item->balances_sum_quantity < $item->min_quantity)
            ->map(fn (Item $item): array => ['item' => $item, 'total' => (float) $item->balances_sum_quantity])
            ->values();
    }

    public function enabled(): bool
    {
        return (bool) $this->context->current()?->license()->hasModule(StockModule::KEY);
    }

    protected function change(Item $item, Location $location, float $delta): void
    {
        $balance = Balance::query()->lockForUpdate()->firstOrCreate(['item_id' => $item->getKey(), 'location_id' => $location->getKey()], ['quantity' => 0]);
        $balance->forceFill(['quantity' => round((float) $balance->quantity + $delta, 3)])->save();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function record(Item $item, string $kind, float $quantity, ?Location $from, ?Location $to, ?User $by, array $extra = []): Movement
    {
        return Movement::query()->create([
            'item_id' => $item->getKey(),
            'kind' => $kind,
            'quantity' => $quantity,
            'from_location_id' => $from?->getKey(),
            'to_location_id' => $to?->getKey(),
            'user_id' => $by?->getKey() ?? auth()->id(),
            'moved_at' => now(),
        ] + $extra);
    }

    protected function enough(Item $item, Location $location, float $quantity): void
    {
        $available = $this->balance($item, $location);

        if ($available + 0.0005 < $quantity) {
            throw new StockException(__('stock.errors.not_enough', ['available' => StockFormat::quantity($available, $item->unit), 'place' => $location->name]));
        }
    }

    protected function positive(float $quantity): void
    {
        if ($quantity <= 0) {
            throw new StockException(__('stock.errors.quantity'));
        }
    }

    protected function notSerialized(Item $item): void
    {
        if ($item->serialized) {
            throw new StockException(__('stock.errors.serialized'));
        }
    }

    protected function inStock(Unit $unit): void
    {
        if ($unit->status !== Unit::IN_STOCK || $unit->location === null) {
            throw new StockException(__('stock.errors.not_in_stock'));
        }
    }
}
