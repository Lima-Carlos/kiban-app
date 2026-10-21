<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Enums\InspectionFrequency;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Enums\ServiceKind;
use App\Modules\Fleet\Enums\VehicleEventType;
use App\Modules\Fleet\Enums\VehicleStatus;
use App\Modules\Fleet\Models\ChecklistItem;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\ServiceRequest;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\VehicleEvent;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inspections (phone-first):
 *
 * - the driver (or the manager / mechanic) checks each item of the company's checklist: OK or problem;
 *   a problem needs a short note (photo optional) and becomes a maintenance request on its own;
 * - photos of the 4 sides and the dashboard, and the odometer (it goes to the vehicle's history);
 * - a problem in a critical item (brakes, tires, lights...) sends the vehicle to "in maintenance";
 * - how often an inspection is expected comes from the settings (daily by default). A missing inspection
 *   never blocks anything: it is a reminder for the driver and a warning for the manager.
 */
class InspectionService
{
    /** Ready items of a new company: key => critical. */
    public const DEFAULT_ITEMS = [
        'tires' => true,
        'lights' => true,
        'brakes' => true,
        'fluids' => false,
        'warning_lights' => true,
        'bodywork' => false,
        'cleanliness' => false,
        'documents' => false,
        'safety_kit' => false,
    ];

    public function __construct(
        protected FleetAccess $access,
        protected OdometerService $odometer,
        protected VehicleService $vehicles,
    ) {}

    /**
     * Active items of the checklist, in order (ready items are created on first use).
     *
     * @return Collection<int, ChecklistItem>
     */
    public function items(): Collection
    {
        $this->ensureDefaultItems();

        return ChecklistItem::query()->active()->get();
    }

    public function ensureDefaultItems(): void
    {
        if (ChecklistItem::query()->exists()) {
            return;
        }

        $sort = 0;

        foreach (self::DEFAULT_ITEMS as $key => $critical) {
            ChecklistItem::create(['key' => $key, 'is_critical' => $critical, 'sort' => $sort += 10]);
        }
    }

    /** Manager, mechanic or a driver of this vehicle. */
    public function canInspect(User $user, ?Vehicle $vehicle = null): bool
    {
        if (! in_array($this->access->fleetRole($user), [FleetRole::Manager, FleetRole::Mechanic, FleetRole::Driver], true)) {
            return false;
        }

        $visible = $this->access->visibleVehicleIds($user);

        return $vehicle === null || $visible === null || in_array($vehicle->getKey(), $visible, true);
    }

    /**
     * Answers come by checklist item id.
     *
     * @param  array{odometer: int, photos: array<string, ?string>, answers: array<int|string, array{ok: bool, note?: ?string, photo?: ?string}>, notes?: ?string}  $data
     */
    public function record(Vehicle $vehicle, array $data, User $user): Inspection
    {
        if (! $this->canInspect($user, $vehicle)) {
            throw new FleetException(__('fleet.denied.not_your_vehicle'));
        }

        if (! $vehicle->isActiveForRecords()) {
            throw new FleetException(__('fleet.errors.vehicle_inactive'));
        }

        foreach (Inspection::PHOTOS as $photo) {
            if (blank($data['photos'][$photo] ?? null)) {
                throw new FleetException(__('fleet.errors.inspection_photos'));
            }
        }

        $answers = $this->answers($data['answers'] ?? []);
        $problems = array_values(array_filter($answers, fn (array $answer): bool => ! $answer['ok']));
        $critical = collect($problems)->contains(fn (array $answer): bool => $answer['critical']);

        $inspection = DB::transaction(function () use ($vehicle, $data, $user, $answers, $problems, $critical): Inspection {
            $inspection = new Inspection([
                'vehicle_id' => $vehicle->getKey(),
                'performed_at' => now(),
                'odometer' => (int) $data['odometer'],
                'photos' => array_intersect_key($data['photos'], array_flip(Inspection::PHOTOS)),
                'answers' => $answers,
                'notes' => $data['notes'] ?? null,
            ]);
            $inspection->forceFill([
                'problems' => count($problems),
                'has_critical' => $critical,
                'performed_by' => $user->getKey(),
            ])->save();

            $this->odometer->record($vehicle, (int) $data['odometer'], OdometerSource::Inspection, $inspection, $user);

            // Each problem becomes a maintenance request (the same path as the driver's button).
            foreach ($problems as $problem) {
                $request = new ServiceRequest([
                    'vehicle_id' => $vehicle->getKey(),
                    'kind' => ServiceKind::Maintenance,
                    'description' => __('fleet.inspections.problem_request', ['item' => $problem['label'], 'note' => $problem['note']]),
                    'photo_path' => $problem['photo'],
                    'inspection_id' => $inspection->getKey(),
                ]);
                $request->forceFill(['requested_by' => $user->getKey()])->save();
            }

            // Critical item: the vehicle should not run until the manager releases it.
            if ($critical && $vehicle->refresh()->status === VehicleStatus::Active) {
                $this->vehicles->setStatus($vehicle, VehicleStatus::Maintenance, $user);
                VehicleEvent::create([
                    'vehicle_id' => $vehicle->getKey(),
                    'type' => VehicleEventType::StoppedByInspection,
                    'data' => ['inspection_id' => $inspection->getKey()],
                    'reason' => collect($problems)->where('critical', true)->pluck('label')->implode(', '),
                    'user_id' => $user->getKey(),
                ]);
            }

            return $inspection;
        });

        app(FleetNotices::class)->inspectionDone($inspection, $user);

        return $inspection;
    }

    /**
     * The answer of every active item, with the item's name and "critical" as they are now (history).
     *
     * @param  array<int|string, array{ok: bool, note?: ?string, photo?: ?string}>  $given
     * @return array<int, array{item_id: int, label: string, critical: bool, ok: bool, note: ?string, photo: ?string}>
     */
    protected function answers(array $given): array
    {
        $answers = [];

        foreach ($this->items() as $item) {
            $answer = $given[$item->getKey()] ?? null;

            if ($answer === null || ! array_key_exists('ok', $answer) || $answer['ok'] === null) {
                throw new FleetException(__('fleet.errors.inspection_unanswered', ['item' => $item->name()]));
            }

            $ok = filter_var($answer['ok'], FILTER_VALIDATE_BOOL);
            $note = trim((string) ($answer['note'] ?? ''));

            if (! $ok && $note === '') {
                throw new FleetException(__('fleet.errors.inspection_note', ['item' => $item->name()]));
            }

            $answers[] = [
                'item_id' => $item->getKey(),
                'label' => $item->name(),
                'critical' => $item->is_critical,
                'ok' => $ok,
                'note' => $ok ? null : $note,
                'photo' => $ok ? null : ($answer['photo'] ?? null),
            ];
        }

        return $answers;
    }

    public function frequency(): InspectionFrequency
    {
        return FleetSetting::current()->inspection_frequency ?? InspectionFrequency::Daily;
    }

    /**
     * Start of the current period (today / this week / this month in the company time zone), in UTC.
     * Null when the company does not ask for inspections.
     */
    public function periodStart(): ?CarbonImmutable
    {
        $now = CarbonImmutable::now(FilamentTimezone::get());

        return match ($this->frequency()) {
            InspectionFrequency::Daily => $now->startOfDay()->utc(),
            InspectionFrequency::Weekly => $now->startOfWeek()->utc(),
            InspectionFrequency::Monthly => $now->startOfMonth()->utc(),
            InspectionFrequency::Free => null,
        };
    }

    /** An active vehicle without an inspection in the current period. */
    public function isDue(Vehicle $vehicle): bool
    {
        $start = $this->periodStart();

        return $start !== null
            && $vehicle->status === VehicleStatus::Active
            && ! $vehicle->inspections()->where('performed_at', '>=', $start)->exists();
    }

    /**
     * Active vehicles still waiting for the inspection of this period.
     *
     * @return Collection<int, Vehicle>
     */
    public function dueVehicles(): Collection
    {
        $start = $this->periodStart();

        if ($start === null) {
            return new Collection;
        }

        return Vehicle::query()
            ->where('status', VehicleStatus::Active->value)
            ->whereDoesntHave('inspections', fn ($query) => $query->where('performed_at', '>=', $start))
            ->orderBy('plate')
            ->get();
    }
}
