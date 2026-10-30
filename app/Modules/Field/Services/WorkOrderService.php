<?php

namespace App\Modules\Field\Services;

use App\Models\User;
use App\Modules\Field\Enums\OrderPriority;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\OrderEvent;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every change of a work order goes through here (screens, quotes and, later, the technician's phone and the
 * API), and each one is written in the order's history:
 * - open: the next number of the company, the customer's address (the main one when none is chosen), and a copy
 *   of the type's checklist, time and price;
 * - schedule / reschedule: a technician of the company and a time (a clash with another order of the same
 *   technician is only warned, never blocked: the service desk may squeeze a quick job in);
 * - take off the agenda, cancel (with the reason). Done and canceled orders do not change any more.
 */
class WorkOrderService
{
    public function __construct(protected FieldAccess $access) {}

    /**
     * @param  array{customer_id: int, site_id?: ?int, service_type_id: int, priority?: ?string, reported?: ?string, internal_notes?: ?string, technician_id?: ?int, scheduled_at?: mixed, duration_minutes?: ?int, origin?: string}  $data
     */
    public function open(array $data, ?User $by): WorkOrder
    {
        $customer = Customer::query()->find($data['customer_id'] ?? null) ?? throw new FieldException(__('field.errors.customer_required'));
        $type = ServiceType::query()->find($data['service_type_id'] ?? null) ?? throw new FieldException(__('field.errors.service_type_required'));

        if (! $customer->is_active) {
            throw new FieldException(__('field.errors.customer_inactive'));
        }

        if (! $type->is_active) {
            throw new FieldException(__('field.errors.service_type_inactive'));
        }

        $site = filled($data['site_id'] ?? null) ? Site::query()->where('customer_id', $customer->getKey())->find($data['site_id']) : $customer->mainSite;

        if (filled($data['site_id'] ?? null) && $site === null) {
            throw new FieldException(__('field.errors.site_of_other_customer'));
        }

        $order = DB::transaction(function () use ($data, $by, $customer, $type, $site): WorkOrder {
            // One number after the other, even with two orders opened at the same second.
            $settings = FieldSetting::query()->lockForUpdate()->first() ?? FieldSetting::current();
            $number = (int) $settings->last_order_number + 1;
            $settings->forceFill(['last_order_number' => $number])->save();

            $order = new WorkOrder;
            $order->forceFill([
                'number' => $number,
                'customer_id' => $customer->getKey(),
                'site_id' => $site?->getKey(),
                'service_type_id' => $type->getKey(),
                'status' => OrderStatus::Open,
                'priority' => ($data['priority'] ?? null) instanceof OrderPriority ? $data['priority'] : (OrderPriority::tryFrom((string) ($data['priority'] ?? '')) ?? OrderPriority::Normal),
                'reported' => $data['reported'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'duration_minutes' => (int) ($data['duration_minutes'] ?? 0) ?: $type->duration_minutes,
                'price_cents' => $type->price_cents,
                'checklist' => $type->checklist,
                'signature_required' => $type->signature_required,
                'photo_required' => $type->photo_required,
                'origin' => $data['origin'] ?? WorkOrder::MANUAL,
                'opened_by' => $by?->getKey(),
            ])->save();

            $this->log($order, 'opened', ['origin' => $order->origin], $by);

            return $order;
        });

        if (filled($data['technician_id'] ?? null) && filled($data['scheduled_at'] ?? null)) {
            $technician = User::query()->find($data['technician_id']) ?? throw new FieldException(__('field.errors.technician_required'));
            $this->schedule($order, $technician, CarbonImmutable::parse($data['scheduled_at']), null, $by);
        }

        return $order->refresh();
    }

    /** Puts the order on a technician's agenda at a time (or moves it). */
    public function schedule(WorkOrder $order, User $technician, CarbonInterface $at, ?int $minutes, ?User $by): WorkOrder
    {
        $this->ensureNotClosed($order);

        if (! $this->access->isTechnician($technician)) {
            throw new FieldException(__('field.errors.not_a_technician', ['name' => $technician->name]));
        }

        $from = ['technician' => $order->technician?->name, 'at' => $order->scheduled_at?->toIso8601ZuluString()];

        $order->forceFill([
            'technician_id' => $technician->getKey(),
            'scheduled_at' => CarbonImmutable::instance($at)->utc()->startOfMinute(),
            'duration_minutes' => $minutes ?: $order->duration_minutes,
            'status' => $order->status === OrderStatus::Open ? OrderStatus::Scheduled : $order->status,
        ])->save();
        $order->setRelation('technician', $technician);

        $this->log($order, $from['at'] === null ? 'scheduled' : 'rescheduled', [
            'technician' => $technician->name,
            'at' => $order->scheduled_at->toIso8601ZuluString(),
            'from_technician' => $from['technician'],
            'from_at' => $from['at'],
        ], $by);

        return $order;
    }

    /** Takes the order off the agenda: it goes back to "open". */
    public function unschedule(WorkOrder $order, ?User $by): WorkOrder
    {
        $this->ensureNotClosed($order);

        if ($order->status !== OrderStatus::Scheduled) {
            throw new FieldException(__('field.errors.not_scheduled'));
        }

        $order->forceFill(['technician_id' => null, 'scheduled_at' => null, 'status' => OrderStatus::Open])->save();
        $this->log($order, 'unscheduled', null, $by);

        return $order;
    }

    public function cancel(WorkOrder $order, string $reason, ?User $by): WorkOrder
    {
        $this->ensureNotClosed($order);

        if (blank($reason)) {
            throw new FieldException(__('field.errors.reason_required'));
        }

        $order->forceFill(['status' => OrderStatus::Canceled, 'canceled_at' => now(), 'cancel_reason' => trim($reason)])->save();
        $this->log($order, 'canceled', ['reason' => trim($reason)], $by);

        return $order;
    }

    /** A note in the history (e.g. "customer asked to come after 2 pm"). */
    public function note(WorkOrder $order, string $text, ?User $by): void
    {
        if (blank($text)) {
            throw new FieldException(__('field.errors.note_required'));
        }

        $this->log($order, 'note', ['text' => trim($text)], $by);
    }

    /**
     * Other pending orders of the technician that overlap this time.
     *
     * @return Collection<int, WorkOrder>
     */
    public function clashes(User $technician, CarbonInterface $at, int $minutes, ?WorkOrder $ignore = null): Collection
    {
        $start = CarbonImmutable::instance($at)->utc();
        $end = $start->addMinutes($minutes);

        return WorkOrder::query()
            ->pending()
            ->where('technician_id', $technician->getKey())
            ->whereNotNull('scheduled_at')
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->where('scheduled_at', '<', $end)
            ->where('scheduled_at', '>=', $start->subHours(24))
            ->with('customer')
            ->get()
            ->filter(fn (WorkOrder $other): bool => $other->endsAt()->gt($start))
            ->values()
            ->toBase();
    }

    /** Done and canceled orders do not change any more. */
    protected function ensureNotClosed(WorkOrder $order): void
    {
        if ($order->status->isClosed()) {
            throw new FieldException(__('field.errors.order_closed'));
        }
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function log(WorkOrder $order, string $type, ?array $data, ?User $by): void
    {
        OrderEvent::create(['order_id' => $order->getKey(), 'type' => $type, 'data' => $data, 'user_id' => $by?->getKey()]);
    }
}
