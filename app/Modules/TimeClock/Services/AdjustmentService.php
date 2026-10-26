<?php

namespace App\Modules\TimeClock\Services;

use App\Models\User;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Filament\TimesheetPage;
use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Corrections of a working day (the "tratamento"), always apart from the punches and with the reason:
 * - the employee asks (forgot to punch, a wrong punch, a medical certificate) and the manager approves or rejects;
 * - a correction made by the manager is approved at once;
 * - no correction for a future day, before the hiring date or in a closed month (part 5).
 * The people involved hear about it in the bell.
 */
class AdjustmentService
{
    public function __construct(
        protected TenantContext $context,
        protected Notifier $notifier,
        protected TimeClockAccess $access,
    ) {}

    /**
     * The employee asks.
     *
     * @param  array{date: string, type: string, time?: ?string, next_day?: bool, punch_id?: ?int, minutes?: ?int, whole_day?: bool, reason: string, attachment_path?: ?string}  $data
     */
    public function request(Employee $employee, array $data, User $by): Adjustment
    {
        $adjustment = $this->build($employee, $data);
        $adjustment->forceFill(['requested_by' => $by->getKey(), 'status' => Adjustment::PENDING])->save();

        $this->notifier->send($this->access->people([TimeClockRole::Manager]), $this->notice(
            'timeclock.notices.adjustment_new',
            ['name' => $employee->person->name, 'date' => fn (): string => $adjustment->date->translatedFormat(__('app.date_format'))],
            'timeclock.notices.adjustment_new_body',
            ['type' => fn (): string => __('timeclock.adjustments.types.'.$adjustment->type), 'reason' => str($adjustment->reason)->limit(100)->toString()],
            TimesheetPage::getUrl(['employee' => $employee->getKey(), 'month' => $adjustment->date->format('Y-m')]),
        ), except: $by);

        return $adjustment;
    }

    /**
     * The manager corrects (approved at once).
     *
     * @param  array{date: string, type: string, time?: ?string, next_day?: bool, punch_id?: ?int, minutes?: ?int, whole_day?: bool, reason: string, attachment_path?: ?string}  $data
     */
    public function record(Employee $employee, array $data, User $by): Adjustment
    {
        $adjustment = $this->build($employee, $data);
        $adjustment->forceFill(['requested_by' => $by->getKey(), 'status' => Adjustment::APPROVED, 'reviewed_by' => $by->getKey(), 'reviewed_at' => now()])->save();

        return $adjustment;
    }

    public function approve(Adjustment $adjustment, User $by, ?string $response = null): void
    {
        $this->review($adjustment, Adjustment::APPROVED, $by, $response);
    }

    public function reject(Adjustment $adjustment, User $by, string $response): void
    {
        if (trim($response) === '') {
            throw new PersonRuleException(__('timeclock.errors.response_required'));
        }

        $this->review($adjustment, Adjustment::REJECTED, $by, $response);
    }

    protected function review(Adjustment $adjustment, string $status, User $by, ?string $response): void
    {
        if (! $adjustment->isPending()) {
            throw new PersonRuleException(__('timeclock.errors.adjustment_closed'));
        }

        $this->assertOpen($adjustment->employee, CarbonImmutable::parse($adjustment->date->toDateString()));
        $adjustment->forceFill(['status' => $status, 'reviewed_by' => $by->getKey(), 'reviewed_at' => now(), 'response' => $response])->save();

        $this->notifier->send([$adjustment->requester], $this->notice(
            'timeclock.notices.adjustment_'.$status,
            ['date' => fn (): string => $adjustment->date->translatedFormat(__('app.date_format'))],
            filled($response) ? 'timeclock.notices.adjustment_response' : null,
            ['text' => (string) $response],
            TimesheetPage::getUrl(['month' => $adjustment->date->format('Y-m')]),
        ), except: $by);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function build(Employee $employee, array $data): Adjustment
    {
        $tz = app(DayCalculator::class)->timezone($employee);
        $date = filled($data['date'] ?? null) ? CarbonImmutable::parse((string) $data['date'], $tz)->startOfDay() : null;
        $type = (string) ($data['type'] ?? '');
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($date === null || ! in_array($type, Adjustment::TYPES, true)) {
            throw new PersonRuleException(__('timeclock.errors.adjustment_incomplete'));
        }

        if ($date->gt(CarbonImmutable::now($tz)->startOfDay())) {
            throw new PersonRuleException(__('timeclock.errors.future_day'));
        }

        if ($employee->admitted_on !== null && $date->toDateString() < $employee->admitted_on->toDateString()) {
            throw new PersonRuleException(__('timeclock.errors.before_admission'));
        }

        if ($reason === '') {
            throw new PersonRuleException(__('timeclock.errors.reason_required'));
        }

        $this->assertOpen($employee, $date);

        $adjustment = new Adjustment([
            'employee_id' => $employee->getKey(),
            'date' => $date->toDateString(),
            'type' => $type,
            'reason' => $reason,
            'attachment_path' => $data['attachment_path'] ?? null,
        ]);

        if ($type === Adjustment::ADD_PUNCH) {
            if (! preg_match('/^\d{2}:\d{2}/', (string) ($data['time'] ?? ''))) {
                throw new PersonRuleException(__('timeclock.errors.time_required'));
            }

            $at = CarbonImmutable::parse($date->toDateString().' '.substr((string) $data['time'], 0, 5), $tz)->addDays(($data['next_day'] ?? false) ? 1 : 0);

            if ($at->isFuture()) {
                throw new PersonRuleException(__('timeclock.errors.future_day'));
            }

            $adjustment->punch_at = $at->utc();
        }

        if ($type === Adjustment::IGNORE_PUNCH) {
            $punch = Punch::query()->where('employee_id', $employee->getKey())->find($data['punch_id'] ?? null);

            // Around the day (a night shift may end the next morning).
            if ($punch === null || $punch->punched_at->lt($date->subHours(12)) || $punch->punched_at->gt($date->addHours(36))) {
                throw new PersonRuleException(__('timeclock.errors.punch_not_found'));
            }

            $adjustment->punch_id = $punch->getKey();
        }

        if ($type === Adjustment::EXCUSE) {
            $minutes = ($data['whole_day'] ?? false) ? null : (int) ($data['minutes'] ?? 0);

            if ($minutes !== null && ($minutes < 1 || $minutes > 1440)) {
                throw new PersonRuleException(__('timeclock.errors.minutes_required'));
            }

            $adjustment->minutes = $minutes;
        }

        return $adjustment;
    }

    /** A closed month does not change (part 5). */
    public function assertOpen(Employee $employee, CarbonImmutable $date): void
    {
        if (app(MonthClosing::class)->isClosed($employee, $date)) {
            throw new PersonRuleException(__('timeclock.errors.month_closed'));
        }
    }

    /**
     * @param  array<string, mixed>  $titleParams
     * @param  array<string, mixed>  $bodyParams
     */
    protected function notice(string $titleKey, array $titleParams, ?string $bodyKey, array $bodyParams, string $url): Notice
    {
        return new Notice($this->context->get()->getKey(), $titleKey, $titleParams, $bodyKey, $bodyParams, $url, 'heroicon-o-pencil-square', 'info');
    }
}
