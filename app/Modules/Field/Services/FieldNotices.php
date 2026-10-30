<?php

namespace App\Modules\Field\Services;

use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\WorkOrder;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Kiban Campo warnings in the bell of the office (managers and the service desk), each one only once:
 * a technician running late or asking to pass an order on, the risk of being late (still at a service when it
 * is time to leave) and a technician who has not arrived yet. Clicking opens the order.
 */
class FieldNotices
{
    public function __construct(protected TenantContext $context, protected FieldAccess $access) {}

    /**
     * @param  array<string, mixed>  $titleParams
     * @param  array<string, mixed>  $bodyParams
     */
    public function office(WorkOrder $order, string $title, array $titleParams, ?string $body, array $bodyParams, string $icon, string $color, ?string $once = null, ?User $except = null): void
    {
        app(Notifier::class)->send($this->team(), new Notice(
            tenantId: $order->tenant_id,
            titleKey: $title,
            titleParams: $titleParams,
            bodyKey: $body,
            bodyParams: $bodyParams,
            url: Notice::path(OrderResource::getUrl('view', ['record' => $order])),
            icon: $icon,
            color: $color,
        ), $once, $except);
    }

    /**
     * @return Collection<int, User>
     */
    public function team(): Collection
    {
        return $this->context->get()->users()->get()
            ->filter(fn (User $user): bool => in_array($this->access->role($user), [FieldRole::Manager, FieldRole::Dispatcher], true))
            ->values()
            ->toBase();
    }
}
