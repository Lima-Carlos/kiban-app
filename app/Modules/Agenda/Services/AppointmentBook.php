<?php

namespace App\Modules\Agenda\Services;

use App\Models\User;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\AppointmentEvent;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\ClientPackage;
use App\Modules\Agenda\Models\Item;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Models\Service;
use App\Modules\Field\Models\Site;
use App\Support\Country;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every change of an appointment goes through here (desk screens, the public booking page, the client's link and
 * the demo data), and each one is written in the appointment's history:
 * - book: the services with the price, time and commission of that moment (per size), the package it uses, the
 *   pick-up address; online bookings ("strict") only on a free time; the desk may squeeze one in (clash = warning);
 * - move (another time or professional), confirm, the status way (arrived, in service, ready, delivered), no-show
 *   (only after the time) and cancel. Delivered, no-show and canceled never change again.
 */
class AppointmentBook
{
    public function __construct(
        protected SlotFinder $slots,
        protected PackageService $packages,
        protected AgendaNotices $notices,
    ) {}

    /**
     * client_package_id: missing = use a package by itself when one fits; null = none.
     *
     * @param  array<string, mixed>  $data  customer_id, item_id, professional_id, starts_at, service_ids, size, notes, pickup, dropoff, pickup_site_id, pickup_address, pickup_from, pickup_until, client_package_id
     */
    public function book(array $data, ?User $by, string $origin = Appointment::DESK, bool $strict = false): Appointment
    {
        $client = Client::query()->find($data['customer_id'] ?? null) ?? throw new AgendaException(__('agenda.errors.client_required'));

        if (! $client->is_active) {
            throw new AgendaException(__('agenda.errors.client_inactive'));
        }

        $item = filled($data['item_id'] ?? null) ? Item::query()->find($data['item_id']) : null;

        if (filled($data['item_id'] ?? null) && ($item === null || (int) $item->customer_id !== (int) $client->getKey())) {
            throw new AgendaException(__('agenda.errors.item_of_other_client'));
        }

        $professional = $this->professional($data['professional_id'] ?? null);
        $services = $this->services($data['service_ids'] ?? []);
        $size = $item?->size ?? ItemSize::tryFrom((string) ($data['size'] ?? ''));
        $start = $this->moment($data['starts_at'] ?? null);
        $minutes = max(AgendaSetting::current()->slot_minutes, $this->slots->minutes($services, $size));

        foreach ($services as $service) {
            if (! $professional->does($service)) {
                throw new AgendaException(__('agenda.errors.professional_does_not', ['name' => $professional->name(), 'service' => $service->name]));
            }
        }

        if ($strict && ! $this->slots->isFree($professional, $start, $minutes)) {
            throw new AgendaException(__('agenda.errors.slot_taken'));
        }

        $day = $start->setTimezone(AgendaSetting::timezone());
        $package = $this->packageFor($data, $client, $item, $services, $day);
        $wantsPickup = (bool) ($data['pickup'] ?? false);
        $wantsDropoff = (bool) ($data['dropoff'] ?? false);

        // The "leva e traz" service alone means both ways.
        if ($services->contains('is_pickup', true) && ! $wantsPickup && ! $wantsDropoff) {
            $wantsPickup = $wantsDropoff = true;
        }

        $pickup = $wantsPickup || $wantsDropoff;

        return DB::transaction(function () use ($data, $by, $origin, $client, $item, $professional, $services, $size, $start, $minutes, $package, $pickup, $wantsPickup, $wantsDropoff): Appointment {
            $appointment = new Appointment;
            $appointment->forceFill([
                'customer_id' => $client->getKey(),
                'item_id' => $item?->getKey(),
                'professional_id' => $professional->getKey(),
                'starts_at' => $start,
                'ends_at' => $start->addMinutes($minutes),
                'status' => AppointmentStatus::Scheduled,
                'origin' => $origin,
                'size' => $size,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'token' => Str::random(48),
                'pickup' => $wantsPickup,
                'dropoff' => $wantsDropoff,
                'pickup_site_id' => $pickup ? $this->pickupSite($client, $data)?->getKey() : null,
                'pickup_from' => $pickup ? $this->time($data['pickup_from'] ?? null) : null,
                'pickup_until' => $pickup ? $this->time($data['pickup_until'] ?? null) : null,
                'client_package_id' => $package?->getKey(),
                'created_by' => $by?->getKey(),
            ]);
            $appointment->price_cents = 0;
            $appointment->save();

            $this->writeLines($appointment, $professional, $services, $size);
            $this->log($appointment, 'booked', ['origin' => $origin], $by);

            return $appointment->refresh();
        });
    }

    /**
     * Another time (and/or professional). Only appointments still to happen; online ("strict") only on a free time.
     */
    public function move(Appointment $appointment, int $professionalId, CarbonImmutable|string $startsAt, ?User $by, bool $strict = false, bool $byClient = false): Appointment
    {
        if (! $appointment->status->isOpen()) {
            throw new AgendaException(__('agenda.errors.not_open'));
        }

        $professional = $this->professional($professionalId);
        $start = $this->moment($startsAt);
        $minutes = $appointment->minutes();

        foreach ($appointment->lines()->with('service')->get() as $line) {
            if ($line->service !== null && ! $professional->does($line->service)) {
                throw new AgendaException(__('agenda.errors.professional_does_not', ['name' => $professional->name(), 'service' => $line->name]));
            }
        }

        if ($strict && ! $this->slots->isFree($professional, $start, $minutes, $appointment->getKey())) {
            throw new AgendaException(__('agenda.errors.slot_taken'));
        }

        if ($appointment->clientPackage !== null) {
            $this->packages->check($appointment->clientPackage, (int) $appointment->customer_id, $appointment->item_id, $appointment->lines->pluck('service_id')->filter()->all(), $start->setTimezone(AgendaSetting::timezone()), $appointment->getKey());
        }

        $from = ['starts_at' => $appointment->starts_at->toIso8601String(), 'professional_id' => $appointment->professional_id];
        $changedProfessional = (int) $appointment->professional_id !== (int) $professional->getKey();

        DB::transaction(function () use ($appointment, $professional, $start, $minutes, $from, $by, $byClient, $changedProfessional): void {
            $appointment->forceFill([
                'professional_id' => $professional->getKey(),
                'starts_at' => $start,
                'ends_at' => $start->addMinutes($minutes),
                // A new time needs a new confirmation (and a new reminder).
                'status' => $byClient ? AppointmentStatus::Confirmed : AppointmentStatus::Scheduled,
                'confirmed_at' => $byClient ? now() : null,
                'reminded_at' => null,
            ])->save();

            if ($changedProfessional) {
                foreach ($appointment->lines()->with('service')->get() as $line) {
                    $line->update(['commission_bp' => $professional->commissionFor($line->service)]);
                }
            }

            $this->log($appointment, 'moved', $from + ['by_client' => $byClient], $by);
        });

        if ($byClient) {
            $this->notices->movedByClient($appointment);
        }

        return $appointment->refresh();
    }

    /** Next step of the way (or a no-show). The final ones never change again. */
    public function setStatus(Appointment $appointment, AppointmentStatus $status, ?User $by, bool $byClient = false): Appointment
    {
        if ($status === AppointmentStatus::Canceled) {
            return $this->cancel($appointment, null, $by, $byClient);
        }

        if (! $appointment->status->canMoveTo($status)) {
            throw new AgendaException(__('agenda.errors.status_change', ['from' => $appointment->status->getLabel(), 'to' => $status->getLabel()]));
        }

        if ($status === AppointmentStatus::NoShow && now()->lt($appointment->starts_at)) {
            throw new AgendaException(__('agenda.errors.no_show_early'));
        }

        $moment = [
            AppointmentStatus::Confirmed->value => 'confirmed_at',
            AppointmentStatus::Arrived->value => 'arrived_at',
            AppointmentStatus::InService->value => 'started_at',
            AppointmentStatus::Ready->value => 'ready_at',
            AppointmentStatus::Delivered->value => 'delivered_at',
            AppointmentStatus::NoShow->value => 'no_show_at',
        ][$status->value];

        $previous = $appointment->status;
        $appointment->forceFill(['status' => $status, $moment => now()])->save();
        $this->log($appointment, 'status', ['from' => $previous->value, 'to' => $status->value, 'by_client' => $byClient], $by);

        if ($byClient && $status === AppointmentStatus::Confirmed) {
            $this->notices->confirmedByClient($appointment);
        }

        return $appointment->refresh();
    }

    public function cancel(Appointment $appointment, ?string $reason, ?User $by, bool $byClient = false): Appointment
    {
        if (! $appointment->status->canMoveTo(AppointmentStatus::Canceled)) {
            throw new AgendaException(__('agenda.errors.not_cancelable'));
        }

        $previous = $appointment->status;
        $appointment->forceFill([
            'status' => AppointmentStatus::Canceled,
            'canceled_at' => now(),
            'canceled_by' => $byClient ? 'client' : 'company',
            'cancel_reason' => filled($reason) ? Str::limit(trim((string) $reason), 250) : null,
        ])->save();
        $this->log($appointment, 'canceled', ['from' => $previous->value, 'by_client' => $byClient, 'reason' => $appointment->cancel_reason], $by);

        if ($byClient) {
            $this->notices->canceledByClient($appointment);
        }

        return $appointment->refresh();
    }

    /** The desk sent the reminder or "pet ready" by WhatsApp (to show it was done). */
    public function markSent(Appointment $appointment, string $what, ?User $by): void
    {
        $column = $what === 'ready' ? 'ready_sent_at' : 'reminded_at';
        $appointment->forceFill([$column => now()])->save();
        $this->log($appointment, $what === 'ready' ? 'ready_sent' : 'reminded', [], $by);
    }

    /** The client can still cancel or reschedule by the link (until "cancel_hours" before the time). */
    public function clientMayChange(Appointment $appointment): bool
    {
        return $appointment->status->isOpen()
            && now()->addHours(AgendaSetting::current()->cancel_hours)->lte($appointment->starts_at);
    }

    public function log(Appointment $appointment, string $type, array $data, ?User $by): void
    {
        AppointmentEvent::create(['appointment_id' => $appointment->getKey(), 'type' => $type, 'data' => $data, 'user_id' => $by?->getKey()]);
    }

    /**
     * @param  Collection<int, Service>  $services
     */
    protected function writeLines(Appointment $appointment, Professional $professional, Collection $services, ?ItemSize $size): void
    {
        $total = 0;

        foreach ($services as $service) {
            $price = $service->priceFor($size);
            $total += $price;
            $appointment->lines()->create([
                'service_id' => $service->getKey(),
                'name' => $service->name,
                'duration_minutes' => $service->is_pickup ? 0 : $service->durationFor($size),
                'price_cents' => $price,
                'commission_bp' => $professional->commissionFor($service),
            ]);
        }

        $appointment->forceFill(['price_cents' => $total])->save();
    }

    protected function professional(mixed $id): Professional
    {
        $professional = filled($id) ? Professional::query()->with('person')->find($id) : null;

        if ($professional === null) {
            throw new AgendaException(__('agenda.errors.professional_required'));
        }

        if (! $professional->is_active) {
            throw new AgendaException(__('agenda.errors.professional_inactive'));
        }

        return $professional;
    }

    /**
     * Main services first, then the add-ons. At least one service that is not an add-on.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Service>
     */
    protected function services(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $services = Service::query()->whereKey($ids)->get();

        if ($services->count() !== count($ids) || $services->contains(fn (Service $service): bool => ! $service->is_active)) {
            throw new AgendaException(__('agenda.errors.service_inactive'));
        }

        if ($services->doesntContain('is_addon', false)) {
            throw new AgendaException(__('agenda.errors.main_service_required'));
        }

        return $services->sortBy(fn (Service $service): string => ($service->is_addon ? '1' : '0').str_pad((string) $service->sort, 5, '0', STR_PAD_LEFT))->values();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Service>  $services
     */
    protected function packageFor(array $data, Client $client, ?Item $item, Collection $services, CarbonImmutable $day): ?ClientPackage
    {
        if (array_key_exists('client_package_id', $data)) {
            if (blank($data['client_package_id'])) {
                return null;
            }

            $package = ClientPackage::query()->find($data['client_package_id']) ?? throw new AgendaException(__('agenda.errors.package_not_usable'));
            $this->packages->check($package, (int) $client->getKey(), $item?->getKey(), $services->modelKeys(), $day);

            return $package;
        }

        foreach ($services->where('is_addon', false) as $service) {
            if (($package = $this->packages->findFor((int) $client->getKey(), $item?->getKey(), $service, $day)) !== null) {
                return $package;
            }
        }

        return null;
    }

    /**
     * The pick-up address: one of the client's addresses, or a new one typed now (saved as the client's address).
     *
     * @param  array<string, mixed>  $data
     */
    protected function pickupSite(Client $client, array $data): ?Site
    {
        if (filled($data['pickup_site_id'] ?? null)) {
            return Site::query()->where('customer_id', $client->getKey())->find($data['pickup_site_id'])
                ?? throw new AgendaException(__('agenda.errors.site_of_other_client'));
        }

        $typed = trim((string) ($data['pickup_address'] ?? ''));

        if ($typed === '') {
            return $client->mainSite;
        }

        $same = Site::query()->where('customer_id', $client->getKey())->whereRaw('lower(street) = ?', [mb_strtolower($typed)])->first();

        if ($same !== null) {
            return $same;
        }

        $site = new Site;
        $site->forceFill([
            'customer_id' => $client->getKey(),
            'label' => __('agenda.fields.pickup_site_label'),
            'street' => Str::limit($typed, 250, ''),
            'country' => Country::current(),
            'is_main' => ! Site::query()->where('customer_id', $client->getKey())->exists(),
        ])->save();

        return $site;
    }

    protected function moment(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }

        if (blank($value)) {
            throw new AgendaException(__('agenda.errors.time_required'));
        }

        return CarbonImmutable::parse((string) $value, AgendaSetting::timezone())->utc();
    }

    protected function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{2}:\d{2}/', $value) ? substr($value, 0, 5) : null;
    }
}
