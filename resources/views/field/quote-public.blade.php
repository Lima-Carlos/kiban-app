@php
    use App\Modules\Field\Enums\QuoteStatus;
    use App\Modules\Field\Services\FieldFormat;
@endphp
<x-layout :title="$quote->label()" :brand="$company" :logo="$logo" :color="$color">
    <h1>{{ __('field.quote_page.title', ['company' => $company]) }}</h1>
    <p>{{ $quote->label() }} · {{ $quote->customer->displayName() }}</p>
    @if ($quote->site)
        <p class="muted">{{ $quote->site->address() }}</p>
    @endif

    <div style="text-align:left;border:1px solid #e5e7eb;border-radius:10px;margin:16px 0;font-size:14px">
        @foreach ($quote->items as $item)
            <div style="display:flex;justify-content:space-between;gap:12px;padding:10px 14px;border-bottom:1px solid #f0f0f0">
                <div>
                    <strong>{{ $item->description }}</strong>
                    <div class="muted" style="color:#6b7280">{{ FieldFormat::quantity($item->quantity, $item->unit) }} × {{ FieldFormat::money($item->unit_price_cents) }}</div>
                </div>
                <div style="white-space:nowrap">{{ FieldFormat::money($item->total_cents) }}</div>
            </div>
        @endforeach
        @if ($quote->discount_cents > 0)
            <div style="display:flex;justify-content:space-between;padding:8px 14px;color:#6b7280">
                <span>{{ __('field.fields.discount') }}</span><span>− {{ FieldFormat::money($quote->discount_cents) }}</span>
            </div>
        @endif
        <div style="display:flex;justify-content:space-between;padding:12px 14px;font-size:17px">
            <strong>{{ __('field.fields.total') }}</strong><strong>{{ FieldFormat::money($quote->total_cents) }}</strong>
        </div>
    </div>

    @if ($quote->notes)
        <p style="text-align:left;white-space:pre-line;color:#39383A">{{ $quote->notes }}</p>
    @endif
    <p class="muted">{{ __('field.quote_page.valid_until', ['date' => $quote->valid_until->translatedFormat(__('app.date_format'))]) }}</p>

    @isset($error)
        <p style="color:#b91c1c;font-weight:bold">{{ $error }}</p>
    @endisset

    @if ($canAnswer)
        <form method="POST" action="{{ route('field.quote.answer', ['token' => $quote->token]) }}" style="text-align:left">
            @csrf
            <label for="name">{{ __('field.quote_page.your_name') }}</label>
            <div class="address"><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="120"></div>
            @error('name')<p style="color:#b91c1c;text-align:left">{{ $message }}</p>@enderror
            <label style="display:flex;gap:8px;align-items:flex-start;font-weight:normal">
                <input type="checkbox" name="agree" value="1" style="margin-top:3px">
                <span>{{ __('field.quote_page.agree_text', ['total' => FieldFormat::money($quote->total_cents), 'company' => $company]) }}</span>
            </label>
            @error('agree')<p style="color:#b91c1c;text-align:left">{{ $message }}</p>@enderror
            <button type="submit" name="answer" value="approve">{{ __('field.quote_page.approve') }}</button>

            <details style="margin-top:14px">
                <summary style="cursor:pointer;color:#6b7280;font-size:14px">{{ __('field.quote_page.decline_open') }}</summary>
                <label for="reason">{{ __('field.quote_page.reason') }}</label>
                <textarea id="reason" name="reason" rows="3" maxlength="1000" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:15px"></textarea>
                <button type="submit" name="answer" value="decline" class="button secondary">{{ __('field.quote_page.decline') }}</button>
            </details>
        </form>
    @elseif ($quote->status === QuoteStatus::Approved)
        <p style="color:#15803d;font-weight:bold">{{ __('field.quote_page.approved', ['name' => $quote->answered_by, 'date' => $quote->answered_at->translatedFormat(__('app.date_format'))]) }}</p>
        <p class="muted">{{ __('field.quote_page.approved_next', ['company' => $company]) }}</p>
    @elseif ($quote->status === QuoteStatus::Declined)
        <p style="font-weight:bold">{{ __('field.quote_page.declined') }}</p>
    @else
        <p style="color:#b45309;font-weight:bold">{{ __('field.quote_page.expired', ['company' => $company]) }}</p>
    @endif
</x-layout>
