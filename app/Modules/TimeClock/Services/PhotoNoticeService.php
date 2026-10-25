<?php

namespace App\Modules\TimeClock\Services;

use App\Models\User;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Kiosk;
use App\Modules\TimeClock\Models\PhotoNotice;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Tenancy\TenantContext;

/**
 * Privacy notice of the punch photos (LGPD). Before the camera opens for the first time, the person reads why the
 * photo is taken (to confirm who punched, against fraud in the time control), who sees it, how long it is kept,
 * that there is no face recognition, and whom to ask. Their acknowledgement ("I am aware" — in a job, consent
 * would not be free, so this is not consent) is recorded with the version of the notice; a new version (new
 * wording, retention or contact) asks again.
 *
 * Without the acknowledgement no photo is stored. If the company allows it, the person may punch without a photo
 * (flagged for the manager); the punch itself is never blocked.
 */
class PhotoNoticeService
{
    /** Raise when the wording of the notice changes (everyone reads it again). */
    public const REVISION = 1;

    public function __construct(protected TenantContext $context) {}

    /** The current version: wording revision, retention and contact. */
    public function version(?TimeClockSetting $settings = null): string
    {
        $settings ??= TimeClockSetting::current();

        return hash('sha256', implode('|', [self::REVISION, TimeClockCleanup::PHOTO_MONTHS, trim((string) $settings->privacy_contact)]));
    }

    /**
     * The paragraphs of the notice, in the current language.
     *
     * @return array<int, string>
     */
    public function paragraphs(): array
    {
        $settings = TimeClockSetting::current();
        $contact = trim((string) $settings->privacy_contact);

        return [
            __('timeclock.privacy.purpose', ['company' => $this->context->get()->name]),
            __('timeclock.privacy.who'),
            trans_choice('timeclock.privacy.retention', TimeClockCleanup::PHOTO_MONTHS, ['months' => TimeClockCleanup::PHOTO_MONTHS]),
            __('timeclock.privacy.no_recognition'),
            $contact !== '' ? __('timeclock.privacy.contact', ['contact' => $contact]) : __('timeclock.privacy.contact_default'),
            __('timeclock.privacy.statement'),
        ];
    }

    public function acknowledged(Employee $employee): bool
    {
        return PhotoNotice::query()->where('employee_id', $employee->getKey())->where('version', $this->version())->exists();
    }

    /** The last acknowledgement of this person (any version). */
    public function latest(Employee $employee): ?PhotoNotice
    {
        return PhotoNotice::query()->with('kiosk')->where('employee_id', $employee->getKey())->latest('id')->first();
    }

    public function record(Employee $employee, string $source, ?User $user = null, ?Kiosk $kiosk = null, ?string $ip = null, ?string $device = null): PhotoNotice
    {
        $existing = PhotoNotice::query()->where('employee_id', $employee->getKey())->where('version', $this->version())->first();

        if ($existing !== null) {
            return $existing;
        }

        $notice = new PhotoNotice;
        $notice->forceFill([
            'employee_id' => $employee->getKey(),
            'version' => $this->version(),
            'retention_months' => TimeClockCleanup::PHOTO_MONTHS,
            'source' => $source === Punch::KIOSK ? Punch::KIOSK : Punch::PHONE,
            'kiosk_id' => $kiosk?->getKey(),
            'user_id' => $user?->getKey(),
            'ip' => $ip,
            'device' => $device !== null ? mb_substr($device, 0, 255) : null,
        ])->save();

        return $notice;
    }

    /** The company shows the "Punch without photo" button (default: yes). */
    public function allowsWithoutPhoto(): bool
    {
        return (bool) TimeClockSetting::current()->allow_punch_without_photo;
    }

    /** "Aware on 25/10/2026 14:03 (phone)" or "Not read yet", for screens and the spreadsheet. */
    public function status(Employee $employee, string $timezone): string
    {
        $notice = $this->latest($employee);

        if ($notice === null) {
            return __('timeclock.privacy.pending');
        }

        $text = __('timeclock.privacy.aware_on', [
            'date' => $notice->created_at->setTimezone($timezone)->translatedFormat(__('app.datetime_format')),
            'where' => $notice->kiosk?->name ?? __('timeclock.punches.sources.'.$notice->source),
        ]);

        return $notice->version === $this->version() ? $text : $text.' · '.__('timeclock.privacy.outdated');
    }
}
