<?php

namespace App\Modules\Field\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldAccess;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * "My work orders" (technician, on the phone): today's services in time order, the next days and the paused
 * ones, each with "How to get there", the customer's WhatsApp and the button to start. Opening this page with
 * signal keeps it (and each order) on the phone, so the day's work also opens without signal.
 */
class MyOrdersPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = FieldModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'campo/minhas-os';

    protected string $view = 'field.my-orders';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(FieldModule::KEY) && app(FieldAccess::class)->role() === FieldRole::Technician;
    }

    public static function getNavigationLabel(): string
    {
        return __('field.my_orders.title');
    }

    public function getTitle(): string
    {
        return __('field.my_orders.title');
    }

    /**
     * Today's, the next 7 days' and the paused orders of this technician.
     *
     * @return array{today: Collection<int, WorkOrder>, next: Collection<int, WorkOrder>, paused: Collection<int, WorkOrder>}
     */
    public function groups(): array
    {
        $today = CarbonImmutable::now(OrderResource::timezone())->startOfDay();
        $orders = WorkOrder::query()
            ->with(['customer', 'site', 'serviceType'])
            ->where('technician_id', auth()->id())
            ->whereIn('status', [OrderStatus::Scheduled->value, OrderStatus::InProgress->value, OrderStatus::Paused->value])
            ->where(fn ($query) => $query->where('status', OrderStatus::Paused->value)->orWhere('scheduled_at', '<', $today->addDays(8)->utc()))
            ->orderBy('scheduled_at')
            ->get();

        $isToday = fn (WorkOrder $order): bool => $order->scheduled_at !== null && $order->scheduled_at->lt($today->endOfDay()->utc());

        return [
            'today' => $orders->filter(fn (WorkOrder $order): bool => $order->status !== OrderStatus::Paused && $isToday($order))->values(),
            'next' => $orders->filter(fn (WorkOrder $order): bool => $order->status !== OrderStatus::Paused && ! $isToday($order))->values(),
            'paused' => $orders->filter(fn (WorkOrder $order): bool => $order->status === OrderStatus::Paused)->values(),
        ];
    }

    /** "How to get there": the customer's point, or the address, on the phone's maps app. */
    public static function directionsUrl(WorkOrder $order): ?string
    {
        $site = $order->site;

        if ($site === null) {
            return null;
        }

        $destination = $site->hasPoint() ? $site->latitude.','.$site->longitude : $site->address();

        return filled($destination) ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($destination) : null;
    }
}
