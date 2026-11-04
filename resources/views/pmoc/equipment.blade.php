{{-- Card of one piece of equipment (opened by its QR label). --}}
@php($item = $this->record())
@php($plan = $item->plan)
<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-wrench-screwdriver">
        <x-slot name="heading">{{ $item->tag }} · {{ __('pmoc.kinds.'.$item->kind) }}</x-slot>
        <x-slot name="description">{{ collect([$item->room, $plan->customer?->displayName(), $plan->site?->address()])->filter()->implode(' · ') }}</x-slot>
        <dl style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:10px 18px;margin:0;font-size:14px" data-pmoc="equipment">
            @foreach ([
                'brand' => $item->brand, 'model' => $item->model, 'serial' => $item->serial,
                'capacity_btu' => $item->capacity_btu ? number_format($item->capacity_btu, 0, ',', '.').' BTU/h' : null,
                'refrigerant' => $item->refrigerant === 'other' ? __('pmoc.other') : $item->refrigerant,
                'installed_on' => $item->installed_on?->translatedFormat(__('app.date_format')),
            ] as $field => $value)
                <div><dt style="font-size:12px;opacity:.7">{{ __('pmoc.fields.'.$field) }}</dt><dd style="margin:2px 0 0;font-weight:600">{{ $value ?: '—' }}</dd></div>
            @endforeach
            <div><dt style="font-size:12px;opacity:.7">{{ __('pmoc.fields.name') }}</dt><dd style="margin:2px 0 0;font-weight:600">{{ $plan->name }}</dd></div>
            <div><dt style="font-size:12px;opacity:.7">{{ __('pmoc.fields.next_due_on') }}</dt><dd style="margin:2px 0 0;font-weight:600">{{ $plan->next_due_on->translatedFormat(__('app.date_format')) }}</dd></div>
        </dl>
    </x-filament::section>

    <p style="margin:0;font-size:13px;opacity:.75">{{ __('pmoc.public.link_help') }} <a href="{{ $item->publicUrl() }}" target="_blank" style="text-decoration:underline">{{ __('pmoc.public.open_link') }}</a></p>

    @include('pmoc.visits', ['visits' => $plan->visits->take(12)])
</x-filament-panels::page>
