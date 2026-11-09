<?php

namespace App\Console\Commands;

use App\Messaging\WhatsAppCloud;
use App\Models\CompanyProfile;
use App\Models\Tenant;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Services\AgendaFormat;
use App\Modules\Agenda\Services\AgendaMessages;
use App\Modules\Agenda\Services\AppointmentBook;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Hourly: from 17 h (company time), tomorrow's appointments not reminded yet get the reminder by WhatsApp by
 * themselves, when the automatic WhatsApp is on (WhatsAppCloud). Off (today), nothing happens: the desk sends
 * the reminders with one tap on the "Lembretes" page.
 */
class SendAgendaReminders extends Command
{
    public const HOUR = 17;

    protected $signature = 'kiban:agenda-reminders';

    protected $description = 'Automatic WhatsApp reminders of tomorrow\'s appointments (only when the WhatsApp API is on)';

    public function handle(WhatsAppCloud $whatsapp, TenantContext $context): int
    {
        if (! $whatsapp->enabled()) {
            $this->components->info(__('agenda.reminders.auto_off'));

            return self::SUCCESS;
        }

        $sent = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($whatsapp, $context, &$sent): void {
            if (! $tenant->license()->hasModule(AgendaModule::KEY) || ! $tenant->license()->canWrite(AgendaModule::KEY)) {
                return;
            }

            $sent += $context->run($tenant, function () use ($whatsapp): int {
                $now = CarbonImmutable::now(AgendaSetting::timezone());

                if ($now->hour < self::HOUR) {
                    return 0;
                }

                $tomorrow = $now->addDay()->startOfDay();
                $count = 0;

                Appointment::query()->with(['client', 'item', 'lines'])
                    ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])
                    ->whereNull('reminded_at')
                    ->whereBetween('starts_at', [$tomorrow->utc(), $tomorrow->endOfDay()->utc()])
                    ->each(function (Appointment $appointment) use ($whatsapp, &$count): void {
                        $ok = $whatsapp->sendTemplate($appointment->client?->phone, 'agenda_reminder', app()->getLocale(), [
                            Str::before(trim((string) $appointment->client?->name), ' '),
                            CompanyProfile::current()->name(),
                            $appointment->servicesLabel(),
                            (string) ($appointment->item?->name ?? ''),
                            AgendaFormat::when($appointment->starts_at),
                            app(AgendaMessages::class)->link($appointment),
                        ]);

                        if ($ok) {
                            app(AppointmentBook::class)->markSent($appointment, 'reminder', null);
                            $count++;
                        }
                    });

                return $count;
            });
        });

        $this->components->info(__('agenda.reminders.auto_sent', ['count' => $sent]));

        return self::SUCCESS;
    }
}
