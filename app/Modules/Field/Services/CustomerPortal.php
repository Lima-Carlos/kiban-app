<?php

namespace App\Modules\Field\Services;

use App\Models\CompanyProfile;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Enums\QuoteStatus;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Pmoc\Models\Equipment;
use App\Modules\Pmoc\PmocModule;
use App\People\PeopleService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * "Your page" for the company's customer (the end customer, no login): one long random link on the company's
 * address with everything about them, in the company's identity (never the Kiban's): the next visits, the quotes
 * to answer, the finished services with their reports and, with the PMOC, each air conditioner and its maintenance.
 * Makes a small company look big. The link can be sent by WhatsApp; "Gerar novo link" cancels the old one.
 */
class CustomerPortal
{
    public function url(Customer $customer): string
    {
        if (blank($customer->portal_token)) {
            $customer->forceFill(['portal_token' => Str::random(48)])->save();
        }

        return route('field.portal', ['token' => $customer->portal_token]);
    }

    /** A new link: the old one stops working. */
    public function renew(Customer $customer): string
    {
        $customer->forceFill(['portal_token' => Str::random(48)])->save();

        return $this->url($customer);
    }

    public function whatsappUrl(Customer $customer): ?string
    {
        if (blank($customer->phone)) {
            return null;
        }

        return PeopleService::whatsappUrl($customer->phone, __('field.portal.whatsapp_message', [
            'name' => Str::before((string) $customer->name, ' '),
            'company' => CompanyProfile::current()->name(),
            'link' => $this->url($customer),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Customer $customer): array
    {
        $profile = CompanyProfile::current();
        $orders = WorkOrder::query()->with(['serviceType', 'site'])->where('customer_id', $customer->getKey());

        return [
            'customer' => $customer,
            'company' => $profile->name(),
            'logo' => $profile->logoDataUrl(),
            'color' => $profile->color(),
            'phone' => $profile->phone ?? null,
            'next' => (clone $orders)->whereIn('status', [OrderStatus::Open->value, OrderStatus::Scheduled->value, OrderStatus::InProgress->value, OrderStatus::Paused->value])
                ->orderByRaw('scheduled_at is null')->orderBy('scheduled_at')->limit(20)->get(),
            'done' => (clone $orders)->where('status', OrderStatus::Done->value)->where('finished_at', '>=', now()->subYear())->orderByDesc('finished_at')->limit(30)->get(),
            'quotes' => Quote::query()->where('customer_id', $customer->getKey())->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Approved->value])
                ->where('created_at', '>=', now()->subYear())->orderByDesc('created_at')->limit(20)->get(),
            'equipment' => $this->equipment($customer),
            'reports' => app(OrderReport::class),
        ];
    }

    /** With the PMOC: the customer's air conditioners (each with its public page). */
    protected function equipment(Customer $customer): iterable
    {
        if (! app(TenantContext::class)->get()->license()->hasModule(PmocModule::KEY)) {
            return [];
        }

        return Equipment::query()->with('plan.site')->where('is_active', true)
            ->whereHas('plan', fn ($query) => $query->where('customer_id', $customer->getKey())->where('is_active', true))
            ->orderBy('tag')->get();
    }
}
