<?php

namespace App\Modules\Field\Filament;

use App\Connections\Bridges\FieldTimeClock;
use App\Models\CompanyProfile;
use App\Modules\Concerns\InModuleMenu;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Models\OrderMaterial;
use App\Modules\Field\Models\OrderPhoto;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\DayPlanner;
use App\Modules\Field\Services\OrderExecutionService;
use App\Modules\Field\Services\OrderReport;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Support\Country;
use App\Support\PhoneAlerts;
use App\Tenancy\TenantContext;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * The work at the customer's place, on the technician's phone, also without signal: "I arrived" (location and
 * the vehicle's km), the checklist, photos, materials, what was done, the customer's signature, and pause or
 * finish. Everything goes to the phone's queue first and is sent by itself (public/js/field-queue.js →
 * OrderSyncController → OrderExecutionService). Only the order's technician opens it.
 */
class ExecuteOrderPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = FieldModule::KEY;

    protected static ?string $slug = 'campo/minhas-os/{order}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'field.execute';

    public int $order;

    public static function canAccess(): bool
    {
        return MyOrdersPage::canAccess();
    }

    public function mount(int $order): void
    {
        abort_unless(static::canAccess(), 403);

        $this->order = $order;

        abort_unless($this->record()->technician_id === auth()->id(), 403);
    }

    public function record(): WorkOrder
    {
        return WorkOrder::query()->with(['customer', 'site', 'serviceType', 'answers', 'photos', 'materials'])->findOrFail($this->order);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record()->label();
    }

    /**
     * Everything the phone needs to work without signal (no photos: only how many of each item were sent).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $order = $this->record();
        $settings = FieldSetting::current();

        return [
            'id' => $order->getKey(),
            'status' => $order->status->value,
            'closed' => in_array($order->status, [OrderStatus::Done, OrderStatus::Canceled], true),
            'checkedIn' => $order->checked_in_at !== null,
            'checklist' => array_values((array) $order->checklist),
            'answers' => $order->answers->pluck('value', 'item_key'),
            'photos' => $order->photos->groupBy(fn (OrderPhoto $photo): string => $photo->item_key ?? '_free')->map->count(),
            'materials' => $order->materials->map(fn (OrderMaterial $material): array => ['id' => $material->client_id, 'description' => $material->description, 'quantity' => (float) $material->quantity, 'unit' => $material->unit])->values(),
            'workDone' => $order->work_done,
            'signedBy' => $order->signed_by,
            'photoRequired' => $order->photo_required,
            'signatureRequired' => $order->signature_required,
            'missing' => app(OrderExecutionService::class)->missing($order),
            'catalog' => Material::query()->active()->orderBy('name')->get(['id', 'name', 'unit'])->map(fn (Material $material): array => ['id' => $material->getKey(), 'name' => $material->name, 'unit' => $material->unit])->values(),
            'vehicles' => $this->vehicles(),
            'needsPoint' => $order->site !== null && ! $order->site->hasPoint(),
            // The report link is ready even before the order reaches the server (it works once it does).
            'reportWhatsapp' => app(OrderReport::class)->whatsappUrl($order),
            // The technician's day: the next service, until when to finish here, the alert to leave.
            'next' => $this->nextService($order),
            'onTheWay' => $order->on_the_way_at !== null,
            'delayMinutes' => $order->delay_minutes,
            'site' => $order->site?->hasPoint() ? ['lat' => (float) $order->site->latitude, 'lng' => (float) $order->site->longitude] : null,
            'travel' => ['speed' => max(5, (int) $settings->travel_speed_kmh), 'factor' => (float) $settings->route_factor],
            'onTheWayMessage' => __('field.execute.on_the_way_message', ['name' => Str::before($order->customer->name, ' '), 'technician' => Str::before((string) auth()->user()->name, ' '), 'company' => CompanyProfile::current()->name()]),
            'customerPhone' => preg_replace('/\D/', '', (string) $order->customer->phone),
            // "I will be late": the message to the customer (the minutes and the new time are filled on the phone).
            'lateMessage' => $this->lateMessage($order),
            'scheduledAt' => $order->scheduled_at?->getTimestampMs(),
            'pushKey' => app(PhoneAlerts::class)->enabled() ? config('kiban.push.public_key') : null,
            'pushUrl' => route('phone-alerts.subscribe'),
            // Written on each photo, with the date, time and place.
            'stamp' => $order->label().' · '.$order->customer->displayName(),
            // Campo + Ponto: "you have not clocked in yet" at the first order, "clock out" after the last one.
            'clock' => app(FieldTimeClock::class)->reminder(auth()->user(), $order),
        ];
    }

    /**
     * The next service of the day (for the card "Next" and the alert to leave), with its own sync address so
     * "I will be late" for it also works without signal.
     *
     * @return array<string, mixed>|null
     */
    protected function nextService(WorkOrder $order): ?array
    {
        $plan = app(DayPlanner::class)->plan($order);

        if ($plan === null) {
            return null;
        }

        $timezone = OrderResource::timezone();
        $next = $plan['next'];

        return [
            'id' => $next->getKey(),
            'label' => $next->label(),
            'customer' => $next->customer->displayName(),
            'time' => $next->scheduled_at->setTimezone($timezone)->format('H:i'),
            'travel' => $plan['travel'],
            'finishBy' => $plan['finish_by']->setTimezone($timezone)->format('H:i'),
            'alertAt' => $plan['alert_at']->toIso8601ZuluString(),
            'url' => static::getUrl(['order' => $next->getKey()]),
            'sync' => route('field.sync', ['order' => $next->getKey()]),
            'customerPhone' => preg_replace('/\D/', '', (string) $next->customer->phone),
            'lateMessage' => $this->lateMessage($next),
            'scheduledAt' => $next->scheduled_at->getTimestampMs(),
        ];
    }

    /** "Hello, Ana! This is Carlos, from X. Sorry: I will be about :minutes min late. I should arrive around :time." */
    protected function lateMessage(WorkOrder $order): string
    {
        return __('field.execute.late_message', [
            'name' => Str::before($order->customer->name, ' '),
            'technician' => Str::before((string) auth()->user()->name, ' '),
            'company' => CompanyProfile::current()->name(),
        ]);
    }

    /**
     * The technician's vehicles in Kiban Frota (for the km at arrival), when the company has it.
     *
     * @return array<int, array{id: int, name: string}>
     */
    protected function vehicles(): array
    {
        if (! app(TenantContext::class)->get()->license()->hasModule(FleetModule::KEY)) {
            return [];
        }

        $driver = app(FleetAccess::class)->driverFor(auth()->user());

        if ($driver === null) {
            return [];
        }

        $vehicles = $driver->any_vehicle ? Vehicle::query()->get() : $driver->vehicles()->get();

        return $vehicles->filter(fn (Vehicle $vehicle): bool => $vehicle->isActiveForRecords())
            ->map(fn (Vehicle $vehicle): array => ['id' => $vehicle->getKey(), 'name' => $vehicle->displayName()])
            ->values()
            ->all();
    }

    public function customerPhone(): ?string
    {
        return Country::formatPhone($this->record()->customer->phone);
    }
}
