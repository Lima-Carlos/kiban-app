<?php

namespace App\Http\Controllers\Agenda;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Enums\ItemSize;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\Service;
use App\Modules\Agenda\Services\AgendaException;
use App\Modules\Agenda\Services\AppointmentBook;
use App\Modules\Agenda\Services\OnlineBooking;
use App\People\PeopleService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The company's public booking page (company address + /agendar, no login): choose the service, the pet's size,
 * add-ons, the professional (or anyone) and the day; then a free time and "who you are" (name, mobile, pet). The
 * client's own page (/agendar/{link}) confirms, reschedules or cancels within the company's rules.
 * Light anti-abuse like the Core's trial: a hidden field robots fill, a minimum time on the page and few tries per minute.
 * The company's identity (logo, color), never the Kiban one: the client is theirs.
 */
class BookingController extends Controller
{
    /** Seconds a person needs at least between opening the page and booking. */
    public const MIN_SECONDS = 3;

    public function show(Request $request, OnlineBooking $booking): View
    {
        $this->available();

        return view('agenda.booking', $this->page($request, $booking));
    }

    public function store(Request $request, OnlineBooking $booking): RedirectResponse|View
    {
        $this->available();
        $this->checkHuman($request);

        $data = $request->validate([
            'service' => ['required', 'integer'],
            'size' => ['nullable', 'string', 'in:'.implode(',', array_column(ItemSize::cases(), 'value'))],
            'extras' => ['nullable', 'array'],
            'extras.*' => ['integer'],
            'professional' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'pet' => ['required', 'string', 'max:120'],
            'species' => ['nullable', 'string', 'in:dog,cat,other'],
            'breed' => ['nullable', 'string', 'max:120'],
            'pickup' => ['nullable', 'boolean'],
            'address' => ['nullable', 'required_if:pickup,1', 'string', 'max:250'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'name' => __('agenda.booking.your_name'),
            'phone' => __('agenda.booking.your_phone'),
            'pet' => __('agenda.booking.pet_name'),
            'address' => __('agenda.booking.address'),
            'time' => __('agenda.fields.time'),
        ]);

        try {
            $appointment = $booking->book([
                'service_id' => $data['service'],
                'addon_ids' => $data['extras'] ?? [],
                'size' => $data['size'] ?? null,
                'professional_id' => $data['professional'] ?? null,
                'date' => $data['date'],
                'time' => $data['time'],
                'name' => $data['name'],
                'phone' => $data['phone'],
                'pet' => $data['pet'],
                'species' => $data['species'] ?? null,
                'breed' => $data['breed'] ?? null,
                'pickup' => (bool) ($data['pickup'] ?? false),
                'address' => $data['address'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (AgendaException $exception) {
            return view('agenda.booking', $this->page($request, $booking) + ['error' => $exception->getMessage()]);
        }

        return redirect()->route('agenda.manage', ['token' => $appointment->token])->with('agenda.booked', true);
    }

    public function manage(Request $request, string $token, OnlineBooking $booking, AppointmentBook $book): View
    {
        $appointment = $this->appointment($token);

        $data = ['appointment' => $appointment, 'mayChange' => $book->clientMayChange($appointment), 'times' => [], 'day' => null] + $this->identity();

        // Reschedule: the free times of the chosen day for the same services (same professional or anyone).
        if ($data['mayChange'] && $request->filled('day')) {
            $day = $this->day((string) $request->query('day'));

            if ($day !== null) {
                $services = $appointment->lines->pluck('service')->filter(fn (?Service $service): bool => $service !== null && ! $service->is_pickup)->values();
                $data['day'] = $day->toDateString();
                $data['times'] = $booking->times($services, $appointment->size ?? $appointment->item?->size, $day, $request->boolean('same') ? $appointment->professional_id : null);
            }
        }

        [$first, $last] = $booking->window();

        return view('agenda.manage', $data + ['first' => $first, 'last' => $last]);
    }

    public function update(Request $request, string $token, OnlineBooking $booking, AppointmentBook $book): RedirectResponse
    {
        $appointment = $this->appointment($token);
        $action = (string) $request->input('action');

        try {
            match ($action) {
                'confirm' => $appointment->status === AppointmentStatus::Scheduled
                    ? $book->setStatus($appointment, AppointmentStatus::Confirmed, null, byClient: true)
                    : null,
                'cancel' => $this->mayChange($book, $appointment) && $book->cancel($appointment, $request->input('reason'), null, byClient: true),
                'move' => $this->move($request, $appointment, $booking, $book),
                default => abort(404),
            };
        } catch (AgendaException $exception) {
            return redirect()->route('agenda.manage', ['token' => $token])->with('agenda.error', $exception->getMessage());
        }

        return redirect()->route('agenda.manage', ['token' => $token])->with('agenda.done', $action);
    }

    protected function move(Request $request, Appointment $appointment, OnlineBooking $booking, AppointmentBook $book): void
    {
        $this->mayChange($book, $appointment);
        $day = $this->day((string) $request->input('day')) ?? throw new AgendaException(__('agenda.errors.slot_taken'));
        $services = $appointment->lines->pluck('service')->filter(fn (?Service $service): bool => $service !== null && ! $service->is_pickup)->values();
        $times = $booking->times($services, $appointment->size ?? $appointment->item?->size, $day, $request->boolean('same') ? $appointment->professional_id : null);
        $professional = $times[(string) $request->input('time')] ?? throw new AgendaException(__('agenda.errors.slot_taken'));

        $book->move($appointment, $professional->getKey(), $day->toDateString().' '.$request->input('time'), null, strict: true, byClient: true);
    }

    protected function mayChange(AppointmentBook $book, Appointment $appointment): bool
    {
        if (! $book->clientMayChange($appointment)) {
            throw new AgendaException(__('agenda.errors.too_late', ['hours' => AgendaSetting::current()->cancel_hours]));
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function page(Request $request, OnlineBooking $booking): array
    {
        $services = $booking->services();
        $main = $services->firstWhere('id', $request->integer('service'));
        $extras = $booking->services(true);
        $chosenExtras = $extras->whereIn('id', array_map('intval', (array) $request->input('extras', [])))->values();
        $size = ItemSize::tryFrom((string) $request->input('size'));
        $chosen = $main ? collect([$main])->merge($chosenExtras) : collect();
        $professionals = $booking->professionals($chosen);
        $professional = $request->integer('professional') ?: null;
        $day = $this->day((string) $request->input('date'));
        [$first, $last] = $booking->window();

        return [
            'open' => $booking->isOpen(),
            'message' => AgendaSetting::current()->booking_message,
            'services' => $services,
            'extras' => $extras,
            'main' => $main,
            'chosenExtras' => $chosenExtras,
            'size' => $size,
            'professionals' => $professionals,
            'professional' => $professional,
            'day' => $day,
            'first' => $first,
            'last' => $last,
            'times' => $main && $day ? $booking->times($chosen, $size, $day, $professional) : [],
            'total' => $chosen->sum(fn (Service $service): int => $service->priceFor($size)),
            'minutes' => $chosen->sum(fn (Service $service): int => $service->durationFor($size)),
            'pickup' => $booking->pickupService(),
            'started' => Crypt::encryptString((string) now()->getTimestamp()),
        ] + $this->identity();
    }

    /**
     * @return array{company: string, logo: ?string, color: string, contact: ?string}
     */
    protected function identity(): array
    {
        $profile = CompanyProfile::current();

        return [
            'company' => $profile->name(),
            'logo' => $profile->logoDataUrl(),
            'color' => $profile->color(),
            'contact' => filled($profile->phone) ? PeopleService::whatsappUrl($profile->phone, '') : null,
        ];
    }

    protected function day(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, AgendaSetting::timezone())->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    protected function available(): void
    {
        abort_unless(app(TenantContext::class)->get()->license()->canWrite(AgendaModule::KEY), 404);
    }

    protected function appointment(string $token): Appointment
    {
        abort_unless(app(TenantContext::class)->get()->license()->hasModule(AgendaModule::KEY), 404);

        return Appointment::query()->with(['client', 'item', 'lines.service', 'professional.person', 'pickupSite'])->where('token', $token)->firstOrFail();
    }

    protected function checkHuman(Request $request): void
    {
        try {
            $started = (int) Crypt::decryptString((string) $request->input('started'));
        } catch (Throwable) {
            $started = 0;
        }

        if (filled($request->input('website')) || $started === 0 || now()->getTimestamp() - $started < self::MIN_SECONDS) {
            throw ValidationException::withMessages(['name' => __('agenda.booking.robot')]);
        }
    }
}
