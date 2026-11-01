<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Agenda\Enums\Species;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\Item;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Models\Service;
use App\Modules\Field\Services\CustomerRules;
use App\Support\Country;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The public booking page's rules: what is offered (services and professionals marked "online"), the days open
 * (from now + notice until "booking_days" ahead), the free times and the booking itself. The client says who they
 * are by the mobile number: an existing client is found by it (nothing of theirs is shown back on the page), a new
 * one is created; the pet is found by its name among the client's pets or created. A phone can hold only a few
 * upcoming online bookings (light anti-abuse, with the throttle and the hidden field of the page).
 */
class OnlineBooking
{
    /** Upcoming online bookings one phone may hold at the same time. */
    public const MAX_OPEN_PER_PHONE = 3;

    public function __construct(protected SlotFinder $slots, protected AppointmentBook $book) {}

    public function isOpen(): bool
    {
        return AgendaSetting::current()->online_booking && $this->services()->isNotEmpty() && $this->professionals(collect())->isNotEmpty();
    }

    /**
     * @return Collection<int, Service>
     */
    public function services(bool $addons = false): Collection
    {
        return Service::query()->active()->where('online', true)->where('is_addon', $addons)->where('is_pickup', false)->orderBy('sort')->get();
    }

    public function pickupService(): ?Service
    {
        return AgendaSetting::current()->pickup_enabled ? Service::query()->active()->where('is_pickup', true)->orderBy('sort')->first() : null;
    }

    /**
     * Professionals on the page who do all these services.
     *
     * @param  Collection<int, Service>  $services
     * @return Collection<int, Professional>
     */
    public function professionals(Collection $services): Collection
    {
        return Professional::query()->active()->where('online', true)->with(['person', 'services'])->orderBy('sort')->get()
            ->filter(fn (Professional $professional): bool => $services->every(fn (Service $service): bool => $professional->does($service)))
            ->values();
    }

    /** First and last day offered. */
    public function window(): array
    {
        $settings = AgendaSetting::current();
        $first = CarbonImmutable::now(AgendaSetting::timezone())->addMinutes($settings->min_notice_minutes)->startOfDay();
        $last = CarbonImmutable::now(AgendaSetting::timezone())->addDays($settings->booking_days)->startOfDay();

        return [$first, $last];
    }

    public function notBefore(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(AgendaSetting::current()->min_notice_minutes);
    }

    /**
     * Free times of a day: [ "09:30" => professional ] (any professional, or the chosen one).
     *
     * @param  Collection<int, Service>  $services
     * @return array<string, Professional>
     */
    public function times(Collection $services, ?ItemSize $size, CarbonImmutable $date, ?int $professionalId): array
    {
        [$first, $last] = $this->window();

        if ($date->lt($first) || $date->gt($last)) {
            return [];
        }

        $professionals = $this->professionals($services)->when($professionalId, fn (Collection $list) => $list->where('id', $professionalId)->values());
        $minutes = max(AgendaSetting::current()->slot_minutes, $this->slots->minutes($services, $size));

        return collect($this->slots->anyOf($professionals, $date, $minutes, $this->notBefore()))
            ->map(fn (array $slot): Professional => $slot['professional'])
            ->all();
    }

    /**
     * Books from the page: finds or creates the client and the pet, then books on a free time only.
     *
     * @param  array<string, mixed>  $data  service_id, addon_ids, size, professional_id (null = any), date, time, name, phone, pet, breed, pickup, address, notes
     */
    public function book(array $data): Appointment
    {
        $main = $this->services()->firstWhere('id', (int) ($data['service_id'] ?? 0)) ?? throw new AgendaException(__('agenda.errors.service_inactive'));
        $addons = $this->services(true)->whereIn('id', array_map('intval', (array) ($data['addon_ids'] ?? [])));
        $services = collect([$main])->merge($addons);
        $pickup = (bool) ($data['pickup'] ?? false) ? $this->pickupService() : null;
        $size = ItemSize::tryFrom((string) ($data['size'] ?? ''));
        $date = CarbonImmutable::parse((string) $data['date'], AgendaSetting::timezone())->startOfDay();
        $times = $this->times($services, $size, $date, filled($data['professional_id'] ?? null) ? (int) $data['professional_id'] : null);
        $professional = $times[(string) ($data['time'] ?? '')] ?? throw new AgendaException(__('agenda.errors.slot_taken'));

        $phone = Country::phone((string) ($data['phone'] ?? ''));

        if ($phone === null || strlen($phone) < 10) {
            throw new AgendaException(__('agenda.errors.phone'));
        }

        return DB::transaction(function () use ($data, $services, $pickup, $size, $date, $professional, $phone): Appointment {
            $client = $this->client((string) $data['name'], $phone);

            $open = Appointment::query()->where('customer_id', $client->getKey())->where('origin', Appointment::ONLINE)
                ->whereIn('status', ['scheduled', 'confirmed'])->where('starts_at', '>', now())->count();

            if ($open >= self::MAX_OPEN_PER_PHONE) {
                throw new AgendaException(__('agenda.errors.too_many', ['count' => self::MAX_OPEN_PER_PHONE]));
            }

            $item = $this->item($client, (string) ($data['pet'] ?? ''), $data['breed'] ?? null, $size, Species::tryFrom((string) ($data['species'] ?? '')));

            $appointment = $this->book->book([
                'customer_id' => $client->getKey(),
                'item_id' => $item?->getKey(),
                'size' => $size?->value,
                'professional_id' => $professional->getKey(),
                'starts_at' => $date->toDateString().' '.$data['time'],
                'service_ids' => $services->merge($pickup ? [$pickup] : [])->modelKeys(),
                'notes' => $data['notes'] ?? null,
                'pickup' => $pickup !== null,
                'dropoff' => $pickup !== null,
                'pickup_address' => $pickup ? ($data['address'] ?? null) : null,
            ], null, Appointment::ONLINE, strict: true);

            app(AgendaNotices::class)->booked($appointment);

            return $appointment;
        });
    }

    /** The client of this mobile number (either phone of the register), or a new one. */
    protected function client(string $name, string $phone): Client
    {
        $client = Client::query()->where(fn ($query) => $query->where('phone', $phone)->orWhere('phone_2', $phone))->orderByDesc('is_active')->first();

        if ($client !== null) {
            if (! $client->is_active) {
                $client->forceFill(['is_active' => true])->save();
            }

            return $client;
        }

        $client = new Client;
        $client->forceFill(app(CustomerRules::class)->prepare(['name' => mb_substr(trim($name), 0, 255), 'phone' => $phone, 'kind' => Client::PERSON]))->save();

        return $client;
    }

    protected function item(Client $client, string $name, ?string $breed, ?ItemSize $size, ?Species $species = null): ?Item
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $item = Item::query()->where('customer_id', $client->getKey())->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($item !== null) {
            // The size told now fills an empty one (the desk's register is never overwritten).
            if ($item->size === null && $size !== null) {
                $item->update(['size' => $size]);
            }

            return $item;
        }

        return Item::create([
            'customer_id' => $client->getKey(),
            'name' => mb_substr($name, 0, 120),
            'breed' => filled($breed) ? mb_substr(trim((string) $breed), 0, 120) : null,
            'size' => $size,
            'species' => $species ?? Species::Dog,
        ]);
    }
}
