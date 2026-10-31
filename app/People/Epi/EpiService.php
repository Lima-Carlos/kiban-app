<?php

namespace App\People\Epi;

use App\Access\AccessManager;
use App\Filament\Pages\EpiItemsPage;
use App\Filament\Pages\EpiPage;
use App\Filament\Pages\MyEpiPage;
use App\Filament\Pages\PersonEpiPage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\FieldModule;
use App\Modules\Fleet\FleetModule;
use App\Modules\ModuleRegistry;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\People\PeopleService;
use App\People\Person;
use App\Support\Country;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Every rule of "PPE and tools" (People screen, every system; not a module): who handles what, the catalog and
 * its simple stock, deliveries signed with the finger (frozen after the signature), returns and replacements, the
 * bell warnings (once per milestone), the "Attention" box and the LGPD copy / deletion.
 *
 * Who handles it: owners, administrators and HR (the same people who see the documents) handle PPE and tools.
 * The Frota and Campo managers also hand out and see TOOLS (not PPE: the PPE record is a labor-law document of
 * HR). A person always sees their own deliveries and signs the pending ones.
 */
class EpiService
{
    /** A delivery waiting for the signature for longer than this warns the managers. */
    public const UNSIGNED_DAYS = 2;

    /** Signatures bigger than this are refused (a finger drawing is a few KB). */
    public const MAX_SIGNATURE_BYTES = 1024 * 1024;

    public function __construct(
        protected TenantContext $context,
        protected Notifier $notifier,
        protected AccessManager $access,
        protected ModuleRegistry $modules,
    ) {}

    // ---------------------------------------------------------------- switch and who handles what

    /**
     * The feature is on for the company. Today it is a People resource of every company with an active license.
     * To sell it later as a package ("People and Safety"), check the license here (e.g.
     * `$tenant->license()->hasFeature('epi')`): every screen, warning and connection reads this one switch.
     */
    public function enabled(?Tenant $tenant = null): bool
    {
        $tenant ??= $this->context->current();

        return $tenant !== null && $tenant->license()->isActive() && Schema::hasTable('epi_items');
    }

    /** PPE and tools of everyone: owners, administrators and HR (the same rule as the documents). */
    public function canManage(?User $user): bool
    {
        $tenant = $this->context->current();

        return $user instanceof User && $tenant !== null && $this->enabled($tenant) && $user->belongsToTenant($tenant)
            && app(PeopleService::class)->canSeeDocuments($user, $tenant);
    }

    /** Only tools: the managers of the Frota and of the Campo (they lend the ladder, the drill, the multimeter). */
    public function handlesTools(?User $user): bool
    {
        $tenant = $this->context->current();

        if (! $user instanceof User || $tenant === null || ! $this->enabled($tenant) || ! $user->belongsToTenant($tenant)) {
            return false;
        }

        $enabled = $this->modules->enabledFor($tenant);

        return collect([FleetModule::KEY, FieldModule::KEY])
            ->contains(fn (string $key): bool => isset($enabled[$key]) && $this->access->role($user, $key) === 'manager');
    }

    /**
     * Kinds of item the person handles ("epi", "tool"); empty = none.
     *
     * @return array<int, string>
     */
    public function kindsFor(?User $user): array
    {
        return match (true) {
            $this->canManage($user) => [EpiKind::Epi->value, EpiKind::Tool->value],
            $this->handlesTools($user) => [EpiKind::Tool->value],
            default => [],
        };
    }

    public function canHandle(?User $user): bool
    {
        return $this->kindsFor($user) !== [];
    }

    /** The company's time zone (signatures are shown in it). */
    public function timezone(): string
    {
        return $this->context->current()?->license()->tenant('timezone') ?: FilamentTimezone::get();
    }

    /** The person of a login in the current company (or null). */
    public function personOf(?User $user): ?Person
    {
        if (! $user instanceof User || ! $this->enabled()) {
            return null;
        }

        return Person::query()->where('user_id', $user->getKey())->first();
    }

    /** Sees this delivery: its own person, or who handles every kind in it. */
    public function canSeeDelivery(?User $user, EpiDelivery $delivery): bool
    {
        if ($user instanceof User && $delivery->person?->user_id === $user->getKey()) {
            return true;
        }

        $kinds = $this->kindsFor($user);

        return $kinds !== [] && $delivery->lines()->whereNotIn('kind', $kinds)->doesntExist();
    }

    // ---------------------------------------------------------------- catalog and stock

    /**
     * Items of the company (the ready ones are created the first time).
     *
     * @param  array<int, string>|null  $kinds
     * @return EloquentCollection<int, EpiItem>
     */
    public function items(?array $kinds = null, bool $activeOnly = true): EloquentCollection
    {
        EpiTemplates::ensure();

        return EpiItem::query()
            ->when($kinds !== null, fn (Builder $query) => $query->ofKinds($kinds))
            ->when($activeOnly, fn (Builder $query) => $query->active())
            ->ordered()->get();
    }

    /**
     * "Luva de vaqueta · G — CA 12345 · estoque 12" for the delivery form.
     *
     * @param  array<int, string>|null  $kinds
     * @return array<int, string>
     */
    public function itemOptions(?array $kinds = null): array
    {
        return $this->items($kinds)->mapWithKeys(fn (EpiItem $item): array => [$item->getKey() => collect([
            $item->fullLabel(),
            filled($item->ca_number) ? __('epi.fields.ca_short').' '.$item->ca_number : null,
            $item->caExpired() ? '⚠ '.__('epi.ca.expired') : null,
            $item->tracksStock() ? __('epi.stock.short', ['count' => $item->stock]) : null,
        ])->filter()->implode(' — ')])->all();
    }

    /** "Entrada no estoque": adds to the stock (an item without stock control starts controlling it). */
    public function addStock(EpiItem $item, int $quantity): EpiItem
    {
        if ($quantity < 1) {
            throw new EpiException(__('epi.errors.quantity'));
        }

        $item->stock = ($item->stock ?? 0) + $quantity;
        $this->resetLowStock($item);
        $item->save();

        return $item;
    }

    protected function moveStock(EpiItem $item, int $change): void
    {
        if (! $item->tracksStock()) {
            return;
        }

        $item->stock += $change;
        $this->resetLowStock($item);
        $item->save();
    }

    /** Back above the minimum: the next drop warns again. */
    protected function resetLowStock(EpiItem $item): void
    {
        if (! $item->isLowStock()) {
            $item->low_stock_notified_at = null;
        }
    }

    // ---------------------------------------------------------------- deliveries

    /**
     * A delivery to a person: the items take what the catalog says now (name, CA, size, cost) and the replacement
     * day from the useful life; the stock goes down (it may go below zero: the delivery is never blocked).
     *
     * @param  array<int, array{item_id: int|string, quantity: int|string}>  $lines
     * @param  array{delivered_on?: mixed, reason?: string|DeliveryReason|null, notes?: ?string}  $data
     * @param  array<int, string>|null  $kinds  the kinds the person who delivers handles
     */
    public function deliver(Person $person, array $lines, array $data, ?User $by = null, ?array $kinds = null): EpiDelivery
    {
        if (! $person->is_active) {
            throw new EpiException(__('epi.errors.inactive_person'));
        }

        $lines = collect($lines)
            ->filter(fn ($line): bool => is_array($line) && filled($line['item_id'] ?? null))
            ->map(fn (array $line): array => ['item_id' => (int) $line['item_id'], 'quantity' => (int) ($line['quantity'] ?? 1)]);

        if ($lines->isEmpty()) {
            throw new EpiException(__('epi.errors.no_items'));
        }

        if ($lines->contains(fn (array $line): bool => $line['quantity'] < 1 || $line['quantity'] > 999)) {
            throw new EpiException(__('epi.errors.quantity'));
        }

        $items = EpiItem::query()->whereKey($lines->pluck('item_id')->all())->get()->keyBy('id');
        $day = CarbonImmutable::parse((string) ($data['delivered_on'] ?? EpiItem::today()->toDateString()))->startOfDay();

        if ($day->greaterThan(EpiItem::today())) {
            throw new EpiException(__('epi.errors.future_date'));
        }

        foreach ($lines as $line) {
            $item = $items->get($line['item_id']);

            if ($item === null || ! $item->is_active) {
                throw new EpiException(__('epi.errors.item_inactive'));
            }

            if ($kinds !== null && ! in_array($item->kind->value, $kinds, true)) {
                throw new EpiException(__('epi.errors.kind_not_allowed'));
            }
        }

        $reason = $data['reason'] ?? DeliveryReason::First;

        return DB::transaction(function () use ($person, $lines, $items, $day, $reason, $data, $by): EpiDelivery {
            $delivery = new EpiDelivery([
                'delivered_on' => $day->toDateString(),
                'reason' => $reason instanceof DeliveryReason ? $reason : DeliveryReason::from($reason),
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            ]);
            $delivery->person_id = $person->getKey();
            $delivery->forceFill(['created_by' => $by?->getKey()])->save();

            foreach ($lines as $line) {
                $item = $items->get($line['item_id']);
                $delivery->lines()->create([
                    'item_id' => $item->getKey(),
                    'quantity' => $line['quantity'],
                    'item_name' => $item->label(),
                    'kind' => $item->kind,
                    'size' => $item->size,
                    'ca_number' => $item->ca_number,
                    'ca_expires_on' => $item->ca_expires_on?->toDateString(),
                    'unit_cost_cents' => $item->unit_cost_cents,
                    'replace_on' => $item->replaceOn($day)?->toDateString(),
                ]);
                $this->moveStock($item, -$line['quantity']);
            }

            return $delivery->load('lines');
        });
    }

    /**
     * Items of the delivery whose stock went below zero (a warning on the screen; never blocks).
     *
     * @return array<int, string>
     */
    public function negativeStock(EpiDelivery $delivery): array
    {
        return EpiItem::query()->whereKey($delivery->lines()->pluck('item_id')->all())->where('stock', '<', 0)->get()
            ->map(fn (EpiItem $item): string => __('epi.stock.negative_item', ['item' => $item->fullLabel(), 'count' => $item->stock]))
            ->all();
    }

    /**
     * "Sign later on the phone": the person (with a login) gets a warning in the bell that opens "My deliveries".
     * False when the person has no login (then it is signed on the manager's phone).
     */
    public function requestSignature(EpiDelivery $delivery, ?User $by = null): bool
    {
        $user = $delivery->person->user;

        if ($user === null || $delivery->isSigned()) {
            return false;
        }

        $this->notifier->send([$user], new Notice(
            $this->context->get()->getKey(),
            'epi.notices.sign_title',
            [],
            'epi.notices.sign_body',
            [
                'count' => $delivery->lines()->count(),
                'date' => fn (): string => $delivery->delivered_on->translatedFormat(__('app.date_format')),
            ],
            MyEpiPage::getUrl(),
            'heroicon-o-pencil-square',
            'warning',
        ), except: $by);

        return true;
    }

    /** The acknowledgment text shown now: the company's own, or the standard one of its country. */
    public function terms(): string
    {
        $own = $this->enabled() ? EpiSetting::current()->terms : null;

        return filled($own) ? trim((string) $own) : $this->standardTerms();
    }

    /** The standard text (NR-6 in Brazil, a generic one elsewhere), in the language of who reads it. */
    public function standardTerms(): string
    {
        return __(Country::isBrazil() ? 'epi.terms.brazil' : 'epi.terms.other', ['company' => $this->context->get()->name]);
    }

    /**
     * The person signs with the finger (on the manager's phone or on their own in "My deliveries"). Keeps the
     * image, the name, the moment, the IP and the device, the text shown, and a hash of everything. Frozen after.
     */
    public function sign(EpiDelivery $delivery, ?string $image, ?string $signerName, ?User $by, bool $ownDevice = false, ?string $ip = null, ?string $userAgent = null): EpiDelivery
    {
        if ($delivery->isSigned()) {
            throw new EpiException(__('epi.errors.already_signed'));
        }

        $name = trim((string) $signerName);

        if ($name === '') {
            throw new EpiException(__('epi.errors.signer_required'));
        }

        $bytes = $this->signatureBytes($image) ?? throw new EpiException(__('epi.errors.signature_invalid'));
        $path = 'tenants/'.$this->context->get()->getKey().'/people/epi/'.$delivery->person_id.'/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $bytes);

        $delivery->forceFill([
            'signature_path' => $path,
            'signer_name' => mb_substr($name, 0, 120),
            'signed_at' => now(),
            'signed_ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'signed_user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            'signed_by' => $by?->getKey(),
            'signed_on_own_device' => $ownDevice,
            'terms' => $this->terms(),
        ]);
        $delivery->signature_hash = $this->hash($delivery);
        $delivery->save();

        return $delivery;
    }

    /** A real drawing as PNG/JPEG (not empty, not too big); the bytes to keep, or null. */
    protected function signatureBytes(?string $image): ?string
    {
        if (! is_string($image) || ! preg_match('#^data:image/(png|jpeg);base64,(.+)$#', $image, $match)) {
            return null;
        }

        $bytes = base64_decode($match[2], true);

        if ($bytes === false || strlen($bytes) > self::MAX_SIGNATURE_BYTES || @getimagesizefromstring($bytes) === false) {
            return null;
        }

        return $this->hasInk($bytes) ? $bytes : null;
    }

    /** Some dark, visible pixels (an empty pad is refused). Without GD, the size check is enough. */
    protected function hasInk(string $bytes): bool
    {
        if (! function_exists('imagecreatefromstring')) {
            return true;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return false;
        }

        [$width, $height] = [imagesx($image), imagesy($image)];
        $ink = 0;

        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));

                if ($color['alpha'] < 100 && ($color['red'] + $color['green'] + $color['blue']) < 450 && ++$ink >= 20) {
                    imagedestroy($image);

                    return true;
                }
            }
        }

        imagedestroy($image);

        return false;
    }

    /** SHA-256 of what was signed (items, text, who, when, where and the image): a changed record does not match. */
    public function hash(EpiDelivery $delivery): string
    {
        $image = filled($delivery->signature_path) && Storage::disk('local')->exists($delivery->signature_path)
            ? hash('sha256', (string) Storage::disk('local')->get($delivery->signature_path))
            : null;

        return hash('sha256', (string) json_encode([
            'tenant' => $delivery->tenant_id,
            'delivery' => $delivery->getKey(),
            'person' => $delivery->person_id,
            'day' => $delivery->delivered_on?->toDateString(),
            'reason' => $delivery->reason?->value,
            'lines' => $delivery->lines()->get()->map(fn (EpiDeliveryLine $line): array => [
                $line->item_id, $line->item_name, $line->size, $line->ca_number, $line->quantity, $line->replace_on?->toDateString(),
            ])->all(),
            'terms' => $delivery->terms,
            'signer' => $delivery->signer_name,
            'at' => $delivery->signed_at?->toIso8601ZuluString(),
            'ip' => $delivery->signed_ip,
            'device' => $delivery->signed_user_agent,
            'image' => $image,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** The signed record is still the same (nothing changed after the signature). */
    public function verify(EpiDelivery $delivery): bool
    {
        return $delivery->isSigned() && hash_equals((string) $delivery->signature_hash, $this->hash($delivery));
    }

    /** "3F9A-0C2B-77D1-A0E4", printed on the record. */
    public function code(EpiDelivery $delivery): ?string
    {
        return $delivery->signature_hash !== null ? implode('-', str_split(strtoupper(substr($delivery->signature_hash, 0, 16)), 4)) : null;
    }

    /** The signature as a data URL (screens and PDF, which have no access to private files). */
    public function signatureDataUrl(EpiDelivery $delivery): ?string
    {
        if (blank($delivery->signature_path) || ! Storage::disk('local')->exists($delivery->signature_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) Storage::disk('local')->get($delivery->signature_path));
    }

    /** A delivery made by mistake, not signed yet and with nothing returned: removed, the stock comes back. */
    public function cancel(EpiDelivery $delivery): void
    {
        if ($delivery->isSigned()) {
            throw new EpiException(__('epi.errors.signed_cannot_cancel'));
        }

        DB::transaction(function () use ($delivery): void {
            $lines = $delivery->lines()->with('item')->get();

            if ($lines->contains(fn (EpiDeliveryLine $line): bool => $line->returned_quantity > 0)) {
                throw new EpiException(__('epi.errors.returned_cannot_cancel'));
            }

            foreach ($lines as $line) {
                $this->moveStock($line->item, $line->quantity);
                $line->delete();
            }

            $delivery->delete();
        });
    }

    // ---------------------------------------------------------------- returns and replacements

    /**
     * Registers a return: back to the stock (reusable), discarded or lost.
     *
     * @param  array{quantity?: int|string, returned_on?: mixed, reason?: string|ReturnReason|null, outcome?: string|ReturnOutcome|null, notes?: ?string}  $data
     */
    public function returnLine(EpiDeliveryLine $line, array $data, ?User $by = null): EpiReturn
    {
        $line->loadMissing(['delivery', 'item']);
        $quantity = (int) ($data['quantity'] ?? $line->openQuantity());

        if ($quantity < 1 || $quantity > $line->openQuantity()) {
            throw new EpiException(__('epi.errors.return_quantity', ['count' => $line->openQuantity()]));
        }

        $day = CarbonImmutable::parse((string) ($data['returned_on'] ?? EpiItem::today()->toDateString()))->startOfDay();

        if ($day->greaterThan(EpiItem::today()) || $day->lessThan(CarbonImmutable::parse($line->delivery->delivered_on->toDateString()))) {
            throw new EpiException(__('epi.errors.return_date'));
        }

        $reason = ($data['reason'] ?? null) instanceof ReturnReason ? $data['reason'] : ReturnReason::tryFrom((string) ($data['reason'] ?? '')) ?? ReturnReason::Finished;
        $outcome = ($data['outcome'] ?? null) instanceof ReturnOutcome ? $data['outcome'] : (ReturnOutcome::tryFrom((string) ($data['outcome'] ?? '')) ?? $reason->defaultOutcome($line->item->reusable));

        return DB::transaction(function () use ($line, $quantity, $day, $reason, $outcome, $data, $by): EpiReturn {
            $return = new EpiReturn([
                'quantity' => $quantity,
                'returned_on' => $day->toDateString(),
                'reason' => $reason,
                'outcome' => $outcome,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            ]);
            $return->line_id = $line->getKey();
            $return->forceFill(['created_by' => $by?->getKey()])->save();

            $line->returned_quantity += $quantity;
            $line->save();

            if ($outcome === ReturnOutcome::Stock) {
                $this->moveStock($line->item, $quantity);
            }

            return $return;
        });
    }

    /**
     * Replacement = the old one comes back (discarded, lost...) + a new delivery (same item or another size),
     * to be signed.
     *
     * @param  array{quantity?: int|string, item_id?: int|string|null, delivered_on?: mixed, reason?: string|ReturnReason|null, outcome?: string|ReturnOutcome|null, notes?: ?string}  $data
     * @param  array<int, string>|null  $kinds
     */
    public function exchange(EpiDeliveryLine $line, array $data, ?User $by = null, ?array $kinds = null): EpiDelivery
    {
        $line->loadMissing(['delivery.person', 'item']);
        $quantity = (int) ($data['quantity'] ?? $line->openQuantity());
        $reason = ($data['reason'] ?? null) instanceof ReturnReason ? $data['reason'] : ReturnReason::tryFrom((string) ($data['reason'] ?? '')) ?? ReturnReason::Wear;
        $day = $data['delivered_on'] ?? EpiItem::today()->toDateString();

        return DB::transaction(function () use ($line, $quantity, $reason, $day, $data, $by, $kinds): EpiDelivery {
            $this->returnLine($line, [
                'quantity' => $quantity,
                'returned_on' => $day,
                'reason' => $reason,
                'outcome' => $data['outcome'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], $by);

            return $this->deliver($line->delivery->person, [['item_id' => $data['item_id'] ?? $line->item_id, 'quantity' => $quantity]], [
                'delivered_on' => $day,
                'reason' => $reason->deliveryReason(),
                'notes' => $data['notes'] ?? null,
            ], $by, $kinds);
        });
    }

    // ---------------------------------------------------------------- lists

    /**
     * Items still with active people (what is with whom), of these kinds.
     *
     * @param  array<int, string>  $kinds
     * @return Builder<EpiDeliveryLine>
     */
    public function openQuery(array $kinds): Builder
    {
        return EpiDeliveryLine::query()->open()->ofKinds($kinds)
            ->whereHas('delivery.person', fn (Builder $query) => $query->where('is_active', true));
    }

    /**
     * Limits a query of lines to one replacement situation (in the database).
     *
     * @param  Builder<EpiDeliveryLine>  $query
     * @return Builder<EpiDeliveryLine>
     */
    public function whereStatus(Builder $query, LineStatus|string $status, ?CarbonInterface $today = null): Builder
    {
        $status = $status instanceof LineStatus ? $status : LineStatus::from($status);
        $today = CarbonImmutable::parse(($today ?? EpiItem::today())->toDateString());
        $column = $query->qualifyColumn('replace_on');

        return match ($status) {
            LineStatus::NoLife => $query->whereNull($column),
            LineStatus::Due => $query->whereDate($column, '<=', $today->toDateString()),
            LineStatus::Soon => $query->whereDate($column, '>', $today->toDateString())->whereDate($column, '<=', $today->addDays(EpiDeliveryLine::SOON_DAYS)->toDateString()),
            LineStatus::Ok => $query->whereDate($column, '>', $today->addDays(EpiDeliveryLine::SOON_DAYS)->toDateString()),
            LineStatus::Returned => $query->whereColumn($query->qualifyColumn('returned_quantity'), '>=', $query->qualifyColumn('quantity')),
        };
    }

    /**
     * Deliveries still waiting for the signature (of active people), optionally for longer than the limit.
     *
     * @param  array<int, string>  $kinds
     * @return Builder<EpiDelivery>
     */
    public function unsignedQuery(array $kinds, bool $late = false): Builder
    {
        return EpiDelivery::query()->unsigned()
            ->whereHas('person', fn (Builder $query) => $query->where('is_active', true))
            ->whereDoesntHave('lines', fn (Builder $query) => $query->whereNotIn('kind', $kinds))
            ->when($late, fn (Builder $query) => $query->where('created_at', '<=', now()->subDays(self::UNSIGNED_DAYS)));
    }

    /**
     * Active PPE whose CA expired or expires within 30 days.
     *
     * @return Builder<EpiItem>
     */
    public function caQuery(bool $expiredOnly = false): Builder
    {
        $today = EpiItem::today();

        return EpiItem::query()->active()->ofKinds([EpiKind::Epi->value])->whereNotNull('ca_expires_on')
            ->whereDate('ca_expires_on', $expiredOnly ? '<' : '<=', ($expiredOnly ? $today : $today->addDays(EpiItem::CA_ALERT_DAYS))->toDateString());
    }

    /**
     * @param  array<int, string>  $kinds
     * @return Builder<EpiItem>
     */
    public function lowStockQuery(array $kinds): Builder
    {
        return EpiItem::query()->active()->ofKinds($kinds)->whereNotNull('stock')->whereNotNull('min_stock')->whereColumn('stock', '<=', 'min_stock');
    }

    /**
     * The numbers of the top of the screen (and of the "Attention" box).
     *
     * @param  array<int, string>  $kinds
     * @return array{due: int, soon: int, unsigned: int, late: int, ca: int, ca_expired: int, low: int}
     */
    public function counts(array $kinds): array
    {
        if ($kinds === [] || ! $this->enabled()) {
            return ['due' => 0, 'soon' => 0, 'unsigned' => 0, 'late' => 0, 'ca' => 0, 'ca_expired' => 0, 'low' => 0];
        }

        $withEpi = in_array(EpiKind::Epi->value, $kinds, true);

        return [
            'due' => $this->whereStatus($this->openQuery($kinds), LineStatus::Due)->count(),
            'soon' => $this->whereStatus($this->openQuery($kinds), LineStatus::Soon)->count(),
            'unsigned' => $this->unsignedQuery($kinds)->count(),
            'late' => $this->unsignedQuery($kinds, late: true)->count(),
            'ca' => $withEpi ? $this->caQuery()->count() : 0,
            'ca_expired' => $withEpi ? $this->caQuery(expiredOnly: true)->count() : 0,
            'low' => $this->lowStockQuery($kinds)->count(),
        ];
    }

    /** Deliveries of the person still waiting for their signature. */
    public function pendingFor(?Person $person): int
    {
        return $person === null ? 0 : EpiDelivery::query()->unsigned()->where('person_id', $person->getKey())->count();
    }

    /**
     * The start page's "Attention" box (every system): for who handles the PPE, what is late; for anyone, their
     * own deliveries waiting for the signature.
     *
     * @return array<int, array{text: string, url: ?string, color: string}>
     */
    public function attention(?User $user): array
    {
        if (! $user instanceof User || ! $this->enabled()) {
            return [];
        }

        $items = [];
        $pending = $this->pendingFor($this->personOf($user));

        if ($pending > 0) {
            $items[] = ['text' => trans_choice('epi.attention.to_sign', $pending, ['count' => $pending]), 'url' => MyEpiPage::getUrl(), 'color' => 'warning'];
        }

        $kinds = $this->kindsFor($user);
        $counts = $this->counts($kinds);
        $parts = array_filter([
            $counts['due'] > 0 ? trans_choice('epi.attention.due', $counts['due'], ['count' => $counts['due']]) : null,
            $counts['ca_expired'] > 0 ? trans_choice('epi.attention.ca', $counts['ca_expired'], ['count' => $counts['ca_expired']]) : null,
            $counts['late'] > 0 ? trans_choice('epi.attention.unsigned', $counts['late'], ['count' => $counts['late']]) : null,
            $counts['low'] > 0 ? trans_choice('epi.attention.low', $counts['low'], ['count' => $counts['low']]) : null,
        ]);

        if ($parts !== []) {
            $items[] = [
                'text' => __('epi.attention.summary', ['parts' => implode(' · ', $parts)]),
                'url' => EpiPage::getUrl(),
                'color' => $counts['due'] + $counts['ca_expired'] > 0 ? 'danger' : 'warning',
            ];
        }

        return $items;
    }

    // ---------------------------------------------------------------- warnings

    /**
     * Who handles this kind in the current company.
     *
     * @return Collection<int, User>
     */
    public function handlers(EpiKind $kind): Collection
    {
        return $this->context->get()->users()->get()
            ->filter(fn (User $user): bool => in_array($kind->value, $this->kindsFor($user), true))
            ->values()
            ->toBase();
    }

    /** Which CA warning an item is at: "start" (30 days), "week" (7 days) or "day" (the day, or expired). */
    public function caMilestone(EpiItem $item, ?CarbonInterface $today = null): ?string
    {
        $days = $item->caDaysLeft($today);

        return match (true) {
            $days === null => null,
            $days <= 0 => 'day',
            $days <= 7 => 'week',
            $days <= EpiItem::CA_ALERT_DAYS => 'start',
            default => null,
        };
    }

    /**
     * The daily routine (inside the company), in the bell of who handles the PPE, each thing once per milestone:
     * replacements by the useful life (7 days before and on the day; one warning per person), CA expiring
     * (30 days, 7 days, the day), deliveries waiting for the signature for more than 2 days (also to the person)
     * and the stock at or below the minimum (once, until it goes back up). Returns how many warnings were sent.
     */
    public function scan(?CarbonInterface $today = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $today = CarbonImmutable::parse(($today ?? EpiItem::today())->toDateString());
        $tenantId = $this->context->get()->getKey();
        $managers = $this->handlers(EpiKind::Epi);
        $sent = 0;

        // 1. Replacements by the useful life, grouped by person.
        $lines = $this->openQuery([EpiKind::Epi->value, EpiKind::Tool->value])->with('delivery.person')
            ->whereNotNull('replace_on')
            ->whereDate('replace_on', '<=', $today->addDays(EpiDeliveryLine::SOON_DAYS)->toDateString())
            ->orderBy('replace_on')->get()
            ->filter(fn (EpiDeliveryLine $line): bool => $this->notifier->firstTime($tenantId, "epi-line:{$line->getKey()}:{$line->replace_on->toDateString()}:".($line->daysLeft($today) <= 0 ? 'day' : 'soon')));

        foreach ($lines->groupBy(fn (EpiDeliveryLine $line): int => (int) $line->delivery->person_id) as $personLines) {
            $person = $personLines->first()->delivery->person;
            $late = $personLines->contains(fn (EpiDeliveryLine $line): bool => $line->daysLeft($today) <= 0);
            $this->notifier->send($managers, new Notice(
                $tenantId,
                'epi.notices.replace_title',
                ['name' => $person->name, 'count' => $personLines->count()],
                'epi.notices.replace_body',
                ['items' => fn (): string => $personLines->map(fn (EpiDeliveryLine $line): string => $line->label().' ('.mb_strtolower($line->statusText($today)).')')->implode(', ')],
                PersonEpiPage::getUrl(['pessoa' => $person->getKey()]),
                'heroicon-o-arrow-path',
                $late ? 'danger' : 'warning',
            ));
            $sent++;
        }

        // 2. CA of the catalog's PPE.
        foreach ($this->caQuery()->get() as $item) {
            $milestone = $this->caMilestone($item, $today);

            if ($milestone === null || ! $this->notifier->firstTime($tenantId, "epi-ca:{$item->getKey()}:{$item->ca_expires_on->toDateString()}:{$milestone}")) {
                continue;
            }

            $days = (int) $item->caDaysLeft($today);
            $this->notifier->send($managers, new Notice(
                $tenantId,
                match (true) {
                    $days < 0 => 'epi.notices.ca_expired',
                    $days === 0 => 'epi.notices.ca_today',
                    default => 'epi.notices.ca_soon',
                },
                ['item' => fn (): string => $item->fullLabel(), 'number' => (string) $item->ca_number, 'days' => $days],
                'epi.notices.ca_body',
                ['date' => fn (): string => $item->ca_expires_on->translatedFormat(__('app.date_format'))],
                EpiItemsPage::getUrl(),
                'heroicon-o-shield-exclamation',
                $days <= 0 ? 'danger' : 'warning',
            ));
            $sent++;
        }

        // 3. Deliveries waiting for the signature for more than 2 days (the managers and the person).
        foreach ($this->unsignedQuery([EpiKind::Epi->value, EpiKind::Tool->value], late: true)->with('person.user')->get() as $delivery) {
            if (! $this->notifier->firstTime($tenantId, "epi-unsigned:{$delivery->getKey()}")) {
                continue;
            }

            $date = fn (): string => $delivery->delivered_on->translatedFormat(__('app.date_format'));
            $handlers = $delivery->lines()->where('kind', EpiKind::Epi->value)->exists() ? $managers : $this->handlers(EpiKind::Tool);
            $this->notifier->send($handlers, new Notice(
                $tenantId, 'epi.notices.unsigned_title', ['name' => $delivery->person->name, 'date' => $date], 'epi.notices.unsigned_body', [],
                PersonEpiPage::getUrl(['pessoa' => $delivery->person_id]), 'heroicon-o-pencil-square', 'warning',
            ));

            if ($delivery->person->user !== null) {
                $this->notifier->send([$delivery->person->user], new Notice(
                    $tenantId, 'epi.notices.sign_title', [], 'epi.notices.sign_body',
                    ['count' => $delivery->lines()->count(), 'date' => $date], MyEpiPage::getUrl(), 'heroicon-o-pencil-square', 'warning',
                ));
            }

            $sent++;
        }

        // 4. Stock at or below the minimum: once, until it goes back up.
        foreach (EpiItem::query()->active()->whereNotNull('low_stock_notified_at')->get() as $item) {
            if (! $item->isLowStock()) {
                $item->forceFill(['low_stock_notified_at' => null])->save();
            }
        }

        foreach ($this->lowStockQuery([EpiKind::Epi->value, EpiKind::Tool->value])->whereNull('low_stock_notified_at')->get() as $item) {
            $this->notifier->send($this->handlers($item->kind), new Notice(
                $tenantId, 'epi.notices.low_title', ['item' => fn (): string => $item->fullLabel()], 'epi.notices.low_body',
                ['count' => $item->stock, 'min' => $item->min_stock], EpiItemsPage::getUrl(), 'heroicon-o-archive-box', 'warning',
            ));
            $item->forceFill(['low_stock_notified_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }

    // ---------------------------------------------------------------- LGPD

    /**
     * The person's deliveries and returns for the LGPD copy (no signature image: only that it exists, with the
     * moment, the IP and the device of the signature).
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportPerson(Person $person): array
    {
        if (! Schema::hasTable('epi_deliveries')) {
            return [];
        }

        return EpiDelivery::query()->with('lines.returns')->where('person_id', $person->getKey())->orderBy('id')->get()
            ->map(fn (EpiDelivery $delivery): array => [
                'delivered_on' => $delivery->delivered_on->toDateString(),
                'reason' => $delivery->reason->value,
                'notes' => $delivery->notes,
                'items' => $delivery->lines->map(fn (EpiDeliveryLine $line): array => [
                    'item' => $line->item_name,
                    'kind' => $line->kind->value,
                    'size' => $line->size,
                    'ca' => $line->ca_number,
                    'quantity' => $line->quantity,
                    'replace_on' => $line->replace_on?->toDateString(),
                    'returns' => $line->returns->map(fn (EpiReturn $return): array => [
                        'quantity' => $return->quantity,
                        'returned_on' => $return->returned_on->toDateString(),
                        'reason' => $return->reason->value,
                        'outcome' => $return->outcome->value,
                        'notes' => $return->notes,
                    ])->all(),
                ])->all(),
                'signed' => $delivery->isSigned(),
                'signer_name' => $delivery->signer_name,
                'signed_at_utc' => $delivery->signed_at?->toIso8601ZuluString(),
                'signed_ip' => $delivery->signed_ip,
                'signed_device' => $delivery->signed_user_agent,
                'signed_on_own_device' => $delivery->signed_on_own_device,
                'terms' => $delivery->terms,
                'with_signature_image' => filled($delivery->signature_path),
            ])
            ->values()
            ->all();
    }

    /**
     * Deletes every delivery of the person, the returns and the signature images (used when the system deletes the
     * person's data). The only way a signed delivery goes away.
     */
    public function purgePerson(Person $person): int
    {
        if (! Schema::hasTable('epi_deliveries')) {
            return 0;
        }

        $deliveries = EpiDelivery::query()->with('lines')->where('person_id', $person->getKey())->get();
        EpiDelivery::$purging = true;

        try {
            DB::transaction(function () use ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    foreach ($delivery->lines as $line) {
                        EpiReturn::query()->where('line_id', $line->getKey())->delete();
                        $line->delete();
                    }

                    if (filled($delivery->signature_path)) {
                        Storage::disk('local')->delete($delivery->signature_path);
                    }

                    $delivery->delete();
                }
            });
        } finally {
            EpiDelivery::$purging = false;
        }

        Storage::disk('local')->deleteDirectory('tenants/'.$person->tenant_id.'/people/epi/'.$person->getKey());

        return $deliveries->count();
    }
}
