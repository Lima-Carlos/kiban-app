<?php

namespace App\Modules\Field\Services;

use App\Models\User;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Models\OrderAnswer;
use App\Modules\Field\Models\OrderEvent;
use App\Modules\Field\Models\OrderMaterial;
use App\Modules\Field\Models\OrderPhoto;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\OdometerService;
use App\Support\Geo;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The work at the customer's place, sent by the technician's phone one piece at a time (also hours later, when
 * the signal comes back). Each piece has its own code: a piece sent twice counts once. The pieces:
 * - check_in: arrival, with the location (and the distance to the customer's point) and the vehicle's km
 *   (which also goes to Kiban Frota, when the company has it); a customer without a point gets this one;
 * - answer: one checklist item (the latest answer wins);
 * - photo: of an item or a free one; material / material_removed; note (what was done);
 * - signature: the customer's name and signature;
 * - pause (with the reason) and finish: finishing checks the required items, the photo and the signature.
 * Only the order's technician sends pieces, and a done or canceled order takes nothing more.
 */
class OrderExecutionService
{
    public const TYPES = ['check_in', 'answer', 'photo', 'material', 'material_removed', 'note', 'signature', 'pause', 'finish', 'on_the_way', 'delay', 'handoff'];

    /** Delays the technician can announce (minutes). */
    public const DELAYS = [15, 30, 60];

    /** Pieces older than this are refused (the office fixes it by hand). */
    public const MAX_DAYS = 7;

    public function __construct(protected FieldPhotoStore $photos, protected TenantContext $context) {}

    /**
     * @param  array{id: string, type: string, taken_at?: int|string|null, data?: array<string, mixed>}  $piece
     */
    public function apply(WorkOrder $order, User $technician, array $piece): void
    {
        $id = (string) ($piece['id'] ?? '');
        $type = (string) ($piece['type'] ?? '');

        if (! Str::isUuid($id) || ! in_array($type, self::TYPES, true)) {
            throw new FieldException(__('field.errors.piece_invalid'));
        }

        if ($order->technician_id !== $technician->getKey()) {
            throw new FieldException(__('field.errors.not_your_order'));
        }

        // Already received (the phone did not get the answer and sent it again).
        if (DB::table('fd_order_sync')->where('tenant_id', $order->tenant_id)->where('client_id', strtolower($id))->exists()) {
            return;
        }

        if (in_array($order->status, [OrderStatus::Done, OrderStatus::Canceled], true)) {
            throw new FieldException(__('field.errors.order_closed'));
        }

        if (! in_array($order->status, [OrderStatus::Scheduled, OrderStatus::InProgress, OrderStatus::Paused], true)) {
            throw new FieldException(__('field.errors.order_not_scheduled'));
        }

        $at = $this->takenAt($piece['taken_at'] ?? null);
        $data = (array) ($piece['data'] ?? []);

        DB::transaction(function () use ($order, $technician, $type, $data, $at, $id): void {
            match ($type) {
                'check_in' => $this->checkIn($order, $technician, $data, $at),
                'answer' => $this->answer($order, $data, $at),
                'photo' => $this->photo($order, $data, $at, $id),
                'material' => $this->material($order, $data, $id),
                'material_removed' => OrderMaterial::query()->where('order_id', $order->getKey())->where('client_id', strtolower((string) ($data['piece'] ?? '')))->delete(),
                'note' => $order->forceFill(['work_done' => filled($data['text'] ?? null) ? mb_substr(trim((string) $data['text']), 0, 5000) : null])->save(),
                'signature' => $this->signature($order, $data, $at),
                'pause' => $this->pause($order, $technician, $data, $at),
                'finish' => $this->finish($order, $technician, $at),
                'on_the_way' => $this->onTheWay($order, $technician, $at),
                'delay' => $this->delay($order, $technician, $data, $at),
                'handoff' => $this->handoff($order, $technician, $data, $at),
            };

            DB::table('fd_order_sync')->insert([
                'tenant_id' => $order->tenant_id, 'order_id' => $order->getKey(), 'client_id' => strtolower($id),
                'type' => $type, 'taken_at' => $at, 'created_at' => now(),
            ]);
        });
    }

    /**
     * What is still missing to finish (texts of the missing items), from what was received.
     *
     * @return array<int, string>
     */
    public function missing(WorkOrder $order): array
    {
        $answers = OrderAnswer::query()->where('order_id', $order->getKey())->pluck('value', 'item_key');
        $photos = OrderPhoto::query()->where('order_id', $order->getKey())->get();
        $missing = [];

        foreach ((array) $order->checklist as $item) {
            if (! ($item['required'] ?? false)) {
                continue;
            }

            $done = $item['type'] === 'photo'
                ? $photos->contains('item_key', $item['key'])
                : static::answered($item['type'], $answers->get($item['key']));

            if (! $done) {
                $missing[] = $item['label'];
            }
        }

        if ($order->photo_required && $photos->isEmpty()) {
            $missing[] = __('field.execute.photo_required');
        }

        if ($order->signature_required && $order->signature_path === null) {
            $missing[] = __('field.execute.signature_required');
        }

        return $missing;
    }

    /** An answer counts when it says something (a tick must be ticked). */
    public static function answered(string $type, ?string $value): bool
    {
        return match ($type) {
            'check' => in_array($value, ['1', 'true'], true),
            'yes_no' => in_array($value, ['yes', 'no'], true),
            default => filled($value),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function checkIn(WorkOrder $order, User $technician, array $data, CarbonImmutable $at): void
    {
        $latitude = is_numeric($data['latitude'] ?? null) ? round((float) $data['latitude'], 6) : null;
        $longitude = is_numeric($data['longitude'] ?? null) ? round((float) $data['longitude'], 6) : null;
        $site = $order->site;
        $distance = null;

        if ($latitude !== null && $longitude !== null && $site !== null) {
            if ($site->hasPoint() && $site->location_source !== Site::TECHNICIAN) {
                $distance = Geo::distance($latitude, $longitude, $site->latitude, $site->longitude);
            }

            // The customer had no point (or only an approximate one) and the technician confirmed it here.
            if (! $site->hasPoint() || (($data['confirm_point'] ?? false) && $site->location_source === Site::SEARCH)) {
                $site->forceFill(['latitude' => $latitude, 'longitude' => $longitude, 'location_source' => Site::TECHNICIAN, 'locate_status' => null, 'located_at' => $at])->save();
                $distance = 0;
            }
        }

        $km = is_numeric($data['km'] ?? null) ? max(0, (int) $data['km']) : null;

        $order->forceFill([
            'checked_in_at' => $order->checked_in_at ?? $at,
            'started_at' => $order->started_at ?? $at,
            'status' => OrderStatus::InProgress,
            'check_in_latitude' => $latitude,
            'check_in_longitude' => $longitude,
            'check_in_accuracy' => is_numeric($data['accuracy'] ?? null) ? (int) round((float) $data['accuracy']) : null,
            'check_in_distance' => $distance,
            'check_in_km' => $km,
        ])->save();

        $note = $km !== null ? $this->odometer($order, $technician, (int) ($data['vehicle_id'] ?? 0), $km, $at) : null;

        $this->log($order, 'checked_in', array_filter(['at' => $at->toIso8601ZuluString(), 'distance' => $distance, 'km' => $km, 'km_note' => $note], fn ($value): bool => $value !== null), $technician);
    }

    /**
     * The km also goes to Kiban Frota (when the company has it and the vehicle is the technician's). A km the
     * fleet refuses (e.g. lower than the last one) stays on the order, with the reason in the history.
     */
    protected function odometer(WorkOrder $order, User $technician, int $vehicleId, int $km, CarbonImmutable $at): ?string
    {
        $tenant = $this->context->get();

        if ($vehicleId === 0 || ! $tenant->license()->hasModule(FleetModule::KEY)) {
            return null;
        }

        $vehicle = Vehicle::query()->find($vehicleId);
        $driver = app(FleetAccess::class)->driverFor($technician);

        if ($vehicle === null || $driver === null || (! $driver->any_vehicle && ! $driver->vehicles()->whereKey($vehicleId)->exists())) {
            return __('field.execute.km_not_your_vehicle');
        }

        $order->forceFill(['vehicle_id' => $vehicle->getKey()])->save();

        try {
            app(OdometerService::class)->record($vehicle, $km, OdometerSource::WorkOrder, $order, $technician, $at->isToday() ? null : $at);
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function answer(WorkOrder $order, array $data, CarbonImmutable $at): void
    {
        $key = (string) ($data['key'] ?? '');

        if (! collect((array) $order->checklist)->contains('key', $key)) {
            throw new FieldException(__('field.errors.piece_invalid'));
        }

        $answer = OrderAnswer::query()->firstOrNew(['order_id' => $order->getKey(), 'item_key' => $key]);

        // An older answer arriving late does not replace a newer one.
        if ($answer->exists && $answer->answered_at->gt($at)) {
            return;
        }

        $value = $data['value'] ?? null;
        $answer->fill([
            'value' => is_bool($value) ? ($value ? '1' : '0') : (filled($value) ? mb_substr(trim((string) $value), 0, 2000) : null),
            'answered_at' => $at,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function photo(WorkOrder $order, array $data, CarbonImmutable $at, string $id): void
    {
        $path = $this->photos->fromDataUrl($data['image'] ?? null, (int) $order->getKey()) ?? throw new FieldException(__('field.errors.photo_invalid'));
        $key = filled($data['key'] ?? null) && collect((array) $order->checklist)->contains('key', $data['key']) ? (string) $data['key'] : null;

        OrderPhoto::create([
            'order_id' => $order->getKey(),
            'item_key' => $key,
            'path' => $path,
            'latitude' => is_numeric($data['latitude'] ?? null) ? (float) $data['latitude'] : null,
            'longitude' => is_numeric($data['longitude'] ?? null) ? (float) $data['longitude'] : null,
            'taken_at' => $at,
            'client_id' => strtolower($id),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function material(WorkOrder $order, array $data, string $id): void
    {
        $material = filled($data['material_id'] ?? null) ? Material::query()->find($data['material_id']) : null;
        $description = $material?->name ?? trim((string) ($data['description'] ?? ''));
        $quantity = (float) ($data['quantity'] ?? 0);

        if ($description === '' || $quantity <= 0) {
            throw new FieldException(__('field.errors.material_invalid'));
        }

        OrderMaterial::create([
            'order_id' => $order->getKey(),
            'material_id' => $material?->getKey(),
            'description' => mb_substr($description, 0, 255),
            'unit' => $material?->unit ?? (filled($data['unit'] ?? null) ? (string) $data['unit'] : 'un'),
            'quantity' => min($quantity, 99999),
            'unit_price_cents' => $material?->price_cents,
            'client_id' => strtolower($id),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function signature(WorkOrder $order, array $data, CarbonImmutable $at): void
    {
        if (blank($data['name'] ?? null)) {
            throw new FieldException(__('field.errors.signature_name_required'));
        }

        $path = $this->photos->fromDataUrl($data['image'] ?? null, (int) $order->getKey()) ?? throw new FieldException(__('field.errors.signature_invalid'));

        $order->forceFill(['signature_path' => $path, 'signed_by' => mb_substr(trim((string) $data['name']), 0, 120), 'signed_at' => $at])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function pause(WorkOrder $order, User $technician, array $data, CarbonImmutable $at): void
    {
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw new FieldException(__('field.errors.reason_required'));
        }

        $order->forceFill(['status' => OrderStatus::Paused, 'pause_reason' => mb_substr($reason, 0, 2000)])->save();
        $this->log($order, 'paused', ['reason' => $reason, 'at' => $at->toIso8601ZuluString()], $technician);
    }

    /** Left for this service (the message to the customer goes from the phone). */
    protected function onTheWay(WorkOrder $order, User $technician, CarbonImmutable $at): void
    {
        $order->forceFill(['on_the_way_at' => $at])->save();
        $this->log($order, 'on_the_way', ['at' => $at->toIso8601ZuluString()], $technician);
    }

    /**
     * "I will be late": the minutes and the reason; the office is told at once.
     *
     * @param  array<string, mixed>  $data
     */
    protected function delay(WorkOrder $order, User $technician, array $data, CarbonImmutable $at): void
    {
        $minutes = (int) ($data['minutes'] ?? 0);

        if ($minutes < 5 || $minutes > 480) {
            throw new FieldException(__('field.errors.delay_invalid'));
        }

        $reason = filled($data['reason'] ?? null) ? mb_substr(trim((string) $data['reason']), 0, 1000) : null;
        $order->forceFill(['delay_minutes' => $minutes, 'delay_reason' => $reason])->save();
        $this->log($order, 'delayed', ['minutes' => $minutes, 'reason' => $reason, 'at' => $at->toIso8601ZuluString()], $technician);

        $expected = $order->scheduled_at?->addMinutes($minutes)->setTimezone(OrderResource::timezone())->format('H:i');
        app(FieldNotices::class)->office($order, 'field.notices.delayed', ['technician' => $technician->name, 'minutes' => $minutes, 'customer' => $order->customer->displayName()],
            'field.notices.delayed_body', ['order' => $order->label(), 'time' => $expected ?? '—', 'reason' => $reason ?? '—'], 'heroicon-o-clock', 'warning', 'delay-'.$order->getKey().'-'.$minutes);
    }

    /**
     * "Pass to another technician": the order goes back to "to schedule", with the reason; the office is told.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handoff(WorkOrder $order, User $technician, array $data, CarbonImmutable $at): void
    {
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw new FieldException(__('field.errors.reason_required'));
        }

        $order->forceFill([
            'status' => OrderStatus::Open,
            'technician_id' => null,
            'scheduled_at' => null,
            'handoff_reason' => mb_substr($reason, 0, 1000),
            'handoff_at' => $at,
        ])->save();
        $this->log($order, 'handed_off', ['technician' => $technician->name, 'reason' => $reason], $technician);

        app(FieldNotices::class)->office($order, 'field.notices.handoff', ['technician' => $technician->name, 'customer' => $order->customer->displayName()],
            'field.notices.handoff_body', ['order' => $order->label(), 'reason' => $reason], 'heroicon-o-arrows-right-left', 'danger', 'handoff-'.$order->getKey().'-'.$at->getTimestamp());
    }

    protected function finish(WorkOrder $order, User $technician, CarbonImmutable $at): void
    {
        $missing = $this->missing($order);

        if ($missing !== []) {
            throw new FieldException(__('field.errors.cannot_finish', ['items' => implode(', ', $missing)]));
        }

        $order->forceFill([
            'status' => OrderStatus::Done,
            'checked_in_at' => $order->checked_in_at ?? $at,
            'started_at' => $order->started_at ?? $at,
            'finished_at' => $at,
            'pause_reason' => null,
        ])->save();

        $this->log($order, 'finished', ['at' => $at->toIso8601ZuluString()], $technician);
    }

    /** When the piece was done on the phone (server time corrected by the phone), never in the future. */
    protected function takenAt(int|string|null $value): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $at = is_numeric($value) ? CarbonImmutable::createFromTimestampMs((int) $value)->startOfSecond() : $now;

        if ($at->gt($now)) {
            return $now;
        }

        if ($at->lt($now->subDays(self::MAX_DAYS))) {
            throw new FieldException(__('field.errors.piece_too_old', ['days' => self::MAX_DAYS]));
        }

        return $at;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function log(WorkOrder $order, string $type, ?array $data, User $by): void
    {
        OrderEvent::create(['order_id' => $order->getKey(), 'type' => $type, 'data' => $data, 'user_id' => $by->getKey()]);
    }
}
