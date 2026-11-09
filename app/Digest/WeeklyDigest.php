<?php

namespace App\Digest;

use App\Filament\Pages\WeeklyDigestPage;
use App\Licensing\CoreClient;
use App\Licensing\CoreUnavailableException;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * "Your week in one minute": every Monday morning, the owner and the administrators of the company get one summary
 * of all their Kiban systems together (the week in numbers from each module, plus what needs attention now).
 * - in the bell, with a link to the "Resumo da semana" page (always);
 * - by e-mail, through the Core (it has the mail service, the templates and, when turned on there, a short
 *   opening paragraph written by Claude from the numbers only). The company can turn the e-mail off.
 * Nobody else sells all these systems together, so this summary is something only the Kiban can give.
 */
class WeeklyDigest
{
    /** Local hour of Monday when it goes out. */
    public const HOUR = 7;

    public function __construct(
        protected ModuleRegistry $modules,
        protected TenantContext $context,
        protected Notifier $notifier,
        protected CoreClient $core,
    ) {}

    /** The previous week (Monday to Sunday) of a moment, in the company's time zone. */
    public static function weekBefore(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->startOfWeek(CarbonImmutable::MONDAY)->subWeek()->startOfDay();
    }

    /**
     * The summary of a week (current company).
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, sections: array<int, array{module: string, name: string, lines: array<int, string>, attention: array<int, string>}>}
     */
    public function build(Tenant $tenant, CarbonImmutable $from, ?User $viewer = null): array
    {
        $to = $from->addDays(6)->endOfDay();
        $viewer ??= $this->managers($tenant)->first();
        $sections = [];

        foreach ($this->modules->enabledFor($tenant) as $key => $module) {
            $lines = $module->weeklySummary($tenant, $from, $to);
            $attention = $viewer !== null ? array_map(fn (array $item): string => (string) $item['text'], $module->attention($viewer)) : [];

            if ($lines !== [] || $attention !== []) {
                $sections[] = ['module' => $key, 'name' => $module->name(), 'lines' => array_values($lines), 'attention' => array_values($attention)];
            }
        }

        return ['from' => $from, 'to' => $to, 'sections' => $sections];
    }

    /**
     * Monday morning of each company: the bell and the e-mail, once per week.
     */
    public function sendDue(Tenant $tenant, CarbonImmutable $now): bool
    {
        return $this->context->run($tenant, function (Tenant $tenant) use ($now): bool {
            $local = $now->setTimezone(FilamentTimezone::get());

            if (! $local->isMonday() || $local->hour < self::HOUR) {
                return false;
            }

            $from = static::weekBefore($local);
            $managers = $this->managers($tenant);

            if ($managers->isEmpty() || ! $this->notifier->firstTime($tenant->getKey(), 'weekly_digest:'.$from->toDateString())) {
                return false;
            }

            $digest = $this->build($tenant, $from, $managers->first());

            if ($digest['sections'] === []) {
                return false;
            }

            $this->notifier->send($managers, new Notice(
                $tenant->getKey(), 'digest.notice_title', ['week' => fn (): string => $this->weekLabel($from)],
                'digest.notice_body', [],
                WeeklyDigestPage::getUrl(['week' => $from->toDateString()]), 'heroicon-o-newspaper', 'info',
            ));

            if ($tenant->weekly_digest_email ?? true) {
                try {
                    $this->core->sendDigest($tenant->subdomain, $this->payload($digest));
                } catch (CoreUnavailableException $exception) {
                    Log::info('Weekly summary e-mail not sent.', ['tenant' => $tenant->subdomain, 'error' => $exception->getMessage()]);
                }
            }

            return true;
        });
    }

    /**
     * What goes to the Core (already in the company's language).
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, sections: array<int, array<string, mixed>>}  $digest
     * @return array<string, mixed>
     */
    public function payload(array $digest): array
    {
        return [
            'week_start' => $digest['from']->toDateString(),
            'week_label' => $this->weekLabel($digest['from']),
            'url' => WeeklyDigestPage::getUrl(['week' => $digest['from']->toDateString()]),
            'sections' => array_map(fn (array $section): array => [
                'name' => $section['name'],
                'lines' => array_slice($section['lines'], 0, 12),
                'attention' => array_slice($section['attention'], 0, 8),
            ], $digest['sections']),
        ];
    }

    public function weekLabel(CarbonImmutable $from): string
    {
        return __('digest.week', ['from' => $from->translatedFormat(__('app.date_format')), 'to' => $from->addDays(6)->translatedFormat(__('app.date_format'))]);
    }

    /**
     * Owner and administrators of the company.
     *
     * @return Collection<int, User>
     */
    public function managers(Tenant $tenant): Collection
    {
        return $tenant->users()->wherePivotIn('role', ['owner', 'admin'])->orderByRaw("case when tenant_user.role = 'owner' then 0 else 1 end")->get();
    }
}
