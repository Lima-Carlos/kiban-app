<?php

namespace App\Modules\Agenda\Services;

use App\Models\User;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\ClientPackage;
use App\Modules\Agenda\Models\Item;
use App\Modules\Agenda\Models\Package;
use App\Modules\Agenda\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Packages ("4 baths in 45 days"): selling one to a client (a copy with the numbers of that day) and finding the
 * package an appointment can use. A session counts while the appointment holds time (booked or done); a canceled
 * appointment or a no-show gives it back.
 */
class PackageService
{
    public function sell(Client $client, Package $package, ?Item $item, ?User $by, ?CarbonImmutable $on = null): ClientPackage
    {
        if (! $package->is_active) {
            throw new AgendaException(__('agenda.errors.package_inactive'));
        }

        if ($item !== null && (int) $item->customer_id !== (int) $client->getKey()) {
            throw new AgendaException(__('agenda.errors.item_of_other_client'));
        }

        $on ??= CarbonImmutable::now(AgendaSetting::timezone())->startOfDay();

        return ClientPackage::create([
            'customer_id' => $client->getKey(),
            'item_id' => $item?->getKey(),
            'package_id' => $package->getKey(),
            'service_id' => $package->service_id,
            'name' => $package->name,
            'quantity' => $package->quantity,
            'price_cents' => $package->price_cents,
            'sold_on' => $on->toDateString(),
            'expires_on' => $on->addDays(max(1, $package->valid_days))->toDateString(),
            'sold_by' => $by?->getKey(),
        ]);
    }

    /**
     * The package to use for this service on this day: valid then, with a session left, of the pet (or of any pet
     * of the client); the one that expires first.
     */
    public function findFor(int $clientId, ?int $itemId, Service $service, CarbonImmutable $day): ?ClientPackage
    {
        return $this->usable($clientId, $itemId, $service, $day)->first();
    }

    /**
     * @return Collection<int, ClientPackage>
     */
    public function usable(int $clientId, ?int $itemId, Service $service, CarbonImmutable $day): Collection
    {
        return ClientPackage::query()
            ->where('customer_id', $clientId)
            ->where('service_id', $service->getKey())
            ->where('sold_on', '<=', $day->toDateString())
            ->where('expires_on', '>=', $day->toDateString())
            ->where(fn ($query) => $query->whereNull('item_id')->when($itemId, fn ($query) => $query->orWhere('item_id', $itemId)))
            ->orderBy('expires_on')
            ->get()
            ->filter(fn (ClientPackage $package): bool => $package->left() > 0)
            ->values();
    }

    /** The package can take this appointment (same client, the service, valid on the day, a session left). */
    public function check(ClientPackage $package, int $clientId, ?int $itemId, array $serviceIds, CarbonImmutable $day, ?int $ignoreAppointment = null): void
    {
        $left = $package->quantity - $package->appointments()->whereIn('status', \App\Modules\Agenda\Enums\AppointmentStatus::holding())
            ->when($ignoreAppointment, fn ($query) => $query->whereKeyNot($ignoreAppointment))->count();

        $fits = (int) $package->customer_id === $clientId
            && in_array((int) $package->service_id, array_map('intval', $serviceIds), true)
            && ($package->item_id === null || (int) $package->item_id === (int) $itemId)
            && $package->sold_on->toDateString() <= $day->toDateString()
            && $package->expires_on->toDateString() >= $day->toDateString();

        if (! $fits) {
            throw new AgendaException(__('agenda.errors.package_not_usable'));
        }

        if ($left <= 0) {
            throw new AgendaException(__('agenda.errors.package_used_up'));
        }
    }

    /**
     * Packages with sessions left that expire in the next days (the "Attention" box).
     *
     * @return Collection<int, ClientPackage>
     */
    public function expiringSoon(int $days = 7): Collection
    {
        $today = CarbonImmutable::now(AgendaSetting::timezone());

        return ClientPackage::query()->with(['client', 'item'])
            ->whereBetween('expires_on', [$today->toDateString(), $today->addDays($days)->toDateString()])
            ->orderBy('expires_on')
            ->get()
            ->filter(fn (ClientPackage $package): bool => $package->left() > 0)
            ->values();
    }
}
