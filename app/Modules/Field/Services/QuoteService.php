<?php

namespace App\Modules\Field\Services;

use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Enums\QuoteStatus;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Filament\Quotes\QuoteResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\QuoteItem;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Models\WorkOrder;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\People\PeopleService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every change of a quote goes through here:
 * - create / update (only before the customer answers): the next number of the company, the lines (each total
 *   is quantity × price), the discount (never above the subtotal) and the total;
 * - send: the customer's link, and a WhatsApp message ready to go;
 * - the customer approves (typing their name) or declines on the link, before the validity date; the answer
 *   keeps when, from which address and device;
 * - an approved quote becomes a work order by itself (the first service of the quote), and the team is told
 *   in the bell. Without any service in it, the service desk opens the order.
 */
class QuoteService
{
    public function __construct(protected WorkOrderService $orders) {}

    /**
     * @param  array<string, mixed>  $data  customer_id, site_id, valid_until, notes, internal_notes, discount_cents
     * @param  array<int, array<string, mixed>>  $items
     */
    public function create(array $data, array $items, ?User $by): Quote
    {
        $customer = Customer::query()->find($data['customer_id'] ?? null) ?? throw new FieldException(__('field.errors.customer_required'));

        // Without an address chosen, the customer's main one.
        if (! array_key_exists('site_id', $data)) {
            $data['site_id'] = $customer->mainSite?->getKey();
        }

        return DB::transaction(function () use ($data, $items, $by, $customer): Quote {
            $settings = FieldSetting::query()->lockForUpdate()->first() ?? FieldSetting::current();
            $number = (int) $settings->last_quote_number + 1;
            $settings->forceFill(['last_quote_number' => $number])->save();

            $quote = new Quote;
            $quote->forceFill([
                'number' => $number,
                'customer_id' => $customer->getKey(),
                'status' => QuoteStatus::Draft,
                'valid_until' => filled($data['valid_until'] ?? null) ? $data['valid_until'] : CarbonImmutable::now(OrderResource::timezone())->addDays($settings->quote_valid_days)->toDateString(),
                'token' => Str::random(48),
                'created_by' => $by?->getKey(),
            ]);

            return $this->fill($quote, $data, $items);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function update(Quote $quote, array $data, array $items): Quote
    {
        if (! $quote->isEditable()) {
            throw new FieldException(__('field.errors.quote_answered'));
        }

        return DB::transaction(fn (): Quote => $this->fill($quote, $data, $items));
    }

    /** The customer's link is ready: the quote counts as sent (it shows on the link from now on). */
    public function send(Quote $quote): Quote
    {
        if ($quote->status === QuoteStatus::Draft) {
            if ($quote->items()->count() === 0) {
                throw new FieldException(__('field.errors.quote_empty'));
            }

            $quote->forceFill(['status' => QuoteStatus::Sent, 'sent_at' => now()])->save();
        }

        return $quote;
    }

    /** The customer's page (no login), on the company's address. */
    public function link(Quote $quote): string
    {
        return route('field.quote', ['token' => $quote->token]);
    }

    /** "Send by WhatsApp": opens WhatsApp with the message and the link (no paid integration). */
    public function whatsappUrl(Quote $quote): string
    {
        $company = app(TenantContext::class)->get()->name;

        return PeopleService::whatsappUrl($quote->customer->phone, __('field.quotes.whatsapp_message', [
            'name' => Str::before($quote->customer->name, ' '),
            'company' => $company,
            'quote' => $quote->label(),
            'total' => FieldFormat::money($quote->total_cents),
            'link' => $this->link($quote),
        ]));
    }

    /**
     * The customer approves on the link, with their name. The quote becomes a work order.
     *
     * @param  array{ip?: ?string, device?: ?string}  $origin
     */
    public function approve(Quote $quote, string $name, array $origin = []): Quote
    {
        $this->ensureCanAnswer($quote);

        if (blank($name)) {
            throw new FieldException(__('field.errors.quote_name_required'));
        }

        DB::transaction(function () use ($quote, $name, $origin): void {
            $quote->forceFill($this->answer(QuoteStatus::Approved, $name, $origin))->save();
            $this->convert($quote, null);
        });

        $this->tell($quote, 'field.notices.quote_approved', $quote->order_id ? 'field.notices.quote_approved_body' : 'field.notices.quote_approved_no_order', 'heroicon-o-check-circle', 'success');

        return $quote->refresh();
    }

    /**
     * @param  array{ip?: ?string, device?: ?string}  $origin
     */
    public function decline(Quote $quote, ?string $name, ?string $reason, array $origin = []): Quote
    {
        $this->ensureCanAnswer($quote);

        $quote->forceFill($this->answer(QuoteStatus::Declined, $name, $origin) + ['decline_reason' => filled($reason) ? trim((string) $reason) : null])->save();
        $this->tell($quote, 'field.notices.quote_declined', 'field.notices.quote_declined_body', 'heroicon-o-x-circle', 'danger');

        return $quote;
    }

    /**
     * Turns an approved quote into a work order: the first service of the quote (or the type the service desk
     * chooses). Returns null when there is no service to use.
     */
    public function convert(Quote $quote, ?User $by, ?int $serviceTypeId = null): ?WorkOrder
    {
        if ($quote->status !== QuoteStatus::Approved) {
            throw new FieldException(__('field.errors.quote_not_approved'));
        }

        if ($quote->order_id !== null) {
            return $quote->order;
        }

        $typeId = $serviceTypeId ?? $quote->items()->whereNotNull('service_type_id')->value('service_type_id');

        if ($typeId === null) {
            return null;
        }

        $order = $this->orders->open([
            'customer_id' => $quote->customer_id,
            'site_id' => $quote->site_id,
            'service_type_id' => $typeId,
            'reported' => $this->summary($quote),
            'origin' => WorkOrder::QUOTE,
        ], $by);

        $order->forceFill(['quote_id' => $quote->getKey()])->save();
        $quote->forceFill(['order_id' => $order->getKey()])->save();

        return $order;
    }

    /** First time the customer opens the link (shown on the quote). */
    public function viewed(Quote $quote): void
    {
        if ($quote->viewed_at === null && $quote->status === QuoteStatus::Sent) {
            $quote->forceFill(['viewed_at' => now()])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function fill(Quote $quote, array $data, array $items): Quote
    {
        if (array_key_exists('site_id', $data)) {
            $quote->site_id = filled($data['site_id']) && Site::query()->where('customer_id', $quote->customer_id)->whereKey($data['site_id'])->exists() ? (int) $data['site_id'] : null;
        }

        foreach (['notes', 'internal_notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $quote->{$field} = filled($data[$field]) ? $data[$field] : null;
            }
        }

        if (filled($data['valid_until'] ?? null)) {
            $quote->valid_until = $data['valid_until'];
        }

        $quote->save();

        $quote->items()->delete();

        foreach (array_values($items) as $index => $item) {
            if (blank($item['description'] ?? null)) {
                continue;
            }

            QuoteItem::create([
                'quote_id' => $quote->getKey(),
                'service_type_id' => $item['service_type_id'] ?? null,
                'material_id' => $item['material_id'] ?? null,
                'description' => trim((string) $item['description']),
                'quantity' => max(0.01, (float) ($item['quantity'] ?? 1)),
                'unit' => filled($item['unit'] ?? null) ? $item['unit'] : 'un',
                'unit_price_cents' => max(0, (int) ($item['unit_price_cents'] ?? 0)),
                'sort' => $index,
            ]);
        }

        $quote->load('items');
        $subtotal = $quote->subtotalCents();
        $discount = min($subtotal, max(0, (int) ($data['discount_cents'] ?? $quote->discount_cents)));
        $quote->forceFill(['discount_cents' => $discount, 'total_cents' => $subtotal - $discount])->save();

        return $quote;
    }

    protected function ensureCanAnswer(Quote $quote): void
    {
        if ($quote->status->isAnswered()) {
            throw new FieldException(__('field.errors.quote_answered'));
        }

        if ($quote->status !== QuoteStatus::Sent) {
            throw new FieldException(__('field.errors.quote_not_sent'));
        }

        if ($quote->isExpired()) {
            throw new FieldException(__('field.errors.quote_expired'));
        }
    }

    /**
     * @param  array{ip?: ?string, device?: ?string}  $origin
     * @return array<string, mixed>
     */
    protected function answer(QuoteStatus $status, ?string $name, array $origin): array
    {
        return [
            'status' => $status,
            'answered_at' => now(),
            'answered_by' => filled($name) ? mb_substr(trim((string) $name), 0, 120) : null,
            'answered_ip' => $origin['ip'] ?? null,
            'answered_device' => isset($origin['device']) ? mb_substr((string) $origin['device'], 0, 255) : null,
        ];
    }

    /** What goes into the work order: the quote's lines and notes. */
    protected function summary(Quote $quote): string
    {
        $lines = $quote->items->map(fn (QuoteItem $item): string => '- '.FieldFormat::quantity($item->quantity, $item->unit).' · '.$item->description)->implode("\n");

        return trim(__('field.quotes.order_summary', ['quote' => $quote->label()])."\n".$lines."\n".($quote->notes ?? ''));
    }

    /** The bell of the managers and the service desk. */
    protected function tell(Quote $quote, string $title, string $body, string $icon, string $color): void
    {
        $people = app(FieldAccess::class);
        $team = app(TenantContext::class)->get()->users()->get()
            ->filter(fn (User $user): bool => in_array($people->role($user), [FieldRole::Manager, FieldRole::Dispatcher], true));

        app(Notifier::class)->send($team, new Notice(
            tenantId: $quote->tenant_id,
            titleKey: $title,
            titleParams: ['quote' => $quote->label(), 'customer' => $quote->customer->displayName()],
            bodyKey: $body,
            bodyParams: ['order' => $quote->order?->label() ?? '', 'reason' => $quote->decline_reason ?? '—'],
            url: Notice::path(QuoteResource::getUrl('view', ['record' => $quote])),
            icon: $icon,
            color: $color,
        ), once: 'quote-answer-'.$quote->getKey());
    }
}
