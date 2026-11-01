<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Filament\Clients\ClientResource;
use App\Modules\Agenda\Filament\Packages\ClientPackageResource;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\AppointmentLine;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\Professional;
use App\Support\Spreadsheet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Agenda's numbers: today (scheduled, arrived, ready, no-shows), the "Attention" box (packages about to expire,
 * clients who miss a lot and are coming soon) and the commissions of a month per professional (screen and Excel).
 */
class AgendaReports
{
    public function __construct(protected PackageService $packages) {}

    /**
     * Today's counts.
     *
     * @return array{scheduled: int, arrived: int, ready: int, delivered: int, no_show: int, total: int}
     */
    public function today(): array
    {
        $day = CarbonImmutable::now(AgendaSetting::timezone());
        $statuses = Appointment::query()
            ->whereBetween('starts_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()])
            ->where('status', '!=', AppointmentStatus::Canceled->value)
            ->pluck('status')
            ->map(fn (AppointmentStatus $status): string => $status->value)
            ->countBy();

        return [
            'scheduled' => (int) $statuses->get('scheduled', 0) + (int) $statuses->get('confirmed', 0),
            'arrived' => (int) $statuses->get('arrived', 0) + (int) $statuses->get('in_service', 0),
            'ready' => (int) $statuses->get('ready', 0),
            'delivered' => (int) $statuses->get('delivered', 0),
            'no_show' => (int) $statuses->get('no_show', 0),
            'total' => (int) $statuses->sum(),
        ];
    }

    /**
     * @return array<int, array{text: string, url: ?string, color: string}>
     */
    public function attention(): array
    {
        $items = [];

        foreach ($this->packages->expiringSoon() as $package) {
            $items[] = [
                'text' => __('agenda.attention.package', [
                    'client' => $package->client->name,
                    'name' => $package->name,
                    'left' => $package->left(),
                    'date' => $package->expires_on->translatedFormat(__('app.date_format')),
                ]),
                'url' => ClientPackageResource::getUrl('index'),
                'color' => 'warning',
            ];
        }

        $limit = AgendaSetting::current()->no_show_flag;

        if ($limit > 0) {
            $now = CarbonImmutable::now();
            $coming = Appointment::query()->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])
                ->whereBetween('starts_at', [$now, $now->addDays(2)])
                ->pluck('customer_id')->unique();
            $flagged = Appointment::query()->where('status', AppointmentStatus::NoShow->value)->whereIn('customer_id', $coming)
                ->selectRaw('customer_id, count(*) as total')->groupBy('customer_id')->havingRaw('count(*) >= ?', [$limit])->pluck('total', 'customer_id');

            foreach (Client::query()->whereKey($flagged->keys())->get() as $client) {
                $items[] = [
                    'text' => __('agenda.attention.no_shows', ['client' => $client->name, 'count' => $flagged->get($client->getKey())]),
                    'url' => ClientResource::getUrl('view', ['record' => $client]),
                    'color' => 'danger',
                ];
            }
        }

        return $items;
    }

    /**
     * Commissions of a month: appointments done (ready or delivered) that started in the month, per professional.
     *
     * @return array<int, array{professional: Professional, appointments: int, revenue: int, commission: int, lines: Collection<int, AppointmentLine>}>
     */
    public function commissions(CarbonImmutable $month): array
    {
        $timezone = AgendaSetting::timezone();
        $start = CarbonImmutable::parse($month->format('Y-m-01'), $timezone)->startOfMonth();
        $end = $start->endOfMonth();

        $appointments = Appointment::query()->with(['lines', 'client', 'item'])
            ->whereIn('status', [AppointmentStatus::Ready->value, AppointmentStatus::Delivered->value])
            ->whereBetween('starts_at', [$start->utc(), $end->utc()])
            ->orderBy('starts_at')
            ->get()
            ->groupBy('professional_id');

        return Professional::query()->with('person')->whereKey($appointments->keys())->get()
            ->sortBy(fn (Professional $professional): string => mb_strtolower($professional->name()))
            ->map(function (Professional $professional) use ($appointments): array {
                $own = $appointments->get($professional->getKey(), collect());
                $lines = $own->flatMap(fn (Appointment $appointment): Collection => $appointment->lines->each(fn (AppointmentLine $line) => $line->setRelation('appointment', $appointment)));

                return [
                    'professional' => $professional,
                    'appointments' => $own->count(),
                    'revenue' => (int) $lines->sum('price_cents'),
                    'commission' => (int) $lines->sum(fn (AppointmentLine $line): int => $line->commissionCents()),
                    'lines' => $lines->values(),
                ];
            })
            ->values()
            ->all();
    }

    /** The month's commissions as an Excel file: a summary and one line per service done (path; deleted after download). */
    public function exportCommissions(CarbonImmutable $month): string
    {
        $rows = $this->commissions($month);
        $money = fn (int $cents): float => round($cents / 100, 2);
        $timezone = AgendaSetting::timezone();
        $currency = AgendaSetting::current()->currency;

        $summary = [[__('agenda.commissions.professional'), __('agenda.commissions.appointments'), __('agenda.commissions.revenue', ['currency' => $currency]), __('agenda.commissions.commission', ['currency' => $currency])]];
        $details = [[__('agenda.fields.date'), __('agenda.commissions.professional'), __('agenda.fields.client'), __('agenda.fields.item'), __('agenda.fields.service'), __('agenda.commissions.price', ['currency' => $currency]), __('agenda.commissions.percent'), __('agenda.commissions.commission', ['currency' => $currency])]];

        foreach ($rows as $row) {
            $summary[] = [$row['professional']->name(), $row['appointments'], $money($row['revenue']), $money($row['commission'])];

            foreach ($row['lines'] as $line) {
                $details[] = [
                    $line->appointment->starts_at->setTimezone($timezone)->translatedFormat(__('app.datetime_format')),
                    $row['professional']->name(),
                    $line->appointment->client?->name,
                    $line->appointment->item?->name,
                    $line->name,
                    $money($line->price_cents),
                    $line->commission_bp / 100,
                    $money($line->commissionCents()),
                ];
            }
        }

        $summary[] = ['__bold__', __('agenda.commissions.total'), (int) collect($rows)->sum('appointments'), $money((int) collect($rows)->sum('revenue')), $money((int) collect($rows)->sum('commission'))];

        return Spreadsheet::write(__('agenda.commissions.file', ['month' => $month->format('Y-m')]), [
            __('agenda.commissions.summary') => $summary,
            __('agenda.commissions.details') => $details,
        ]);
    }
}
