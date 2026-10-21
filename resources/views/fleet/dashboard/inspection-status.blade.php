@php
    use App\Modules\Fleet\Enums\InspectionFrequency;
    use App\Modules\Fleet\Filament\Inspections\InspectionResource;
    use App\Modules\Fleet\Filament\Requests\ServiceRequestResource;
    use App\Modules\Fleet\Filament\Vehicles\VehicleResource;

    $frequency = $this->frequency();
    $due = $this->dueVehicles();
    $total = $this->activeVehicles();
    $problems = $this->openProblems();
@endphp

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-clipboard-document-check">
        <x-slot name="heading">{{ __('fleet.inspections.plural_title') }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::button tag="a" size="sm" color="gray" :href="InspectionResource::getUrl('index')">{{ __('fleet.home.see_all') }}</x-filament::button>
        </x-slot>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px">
            @if ($frequency !== InspectionFrequency::Free)
                <div>
                    <div style="font-weight:600;margin-bottom:6px">
                        {{ __('fleet.inspections.done_in_period.'.$frequency->value, ['done' => $total - $due->count(), 'total' => $total]) }}
                    </div>
                    @forelse ($due as $vehicle)
                        <a href="{{ VehicleResource::getUrl('view', ['record' => $vehicle]) }}" style="display:flex;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid rgba(127,127,127,.2);color:inherit;text-decoration:none">
                            <span><strong>{{ $vehicle->plate }}</strong> · {{ $vehicle->brand }} {{ $vehicle->model }}</span>
                            <x-filament::badge color="warning">{{ __('fleet.inspections.missing') }}</x-filament::badge>
                        </a>
                    @empty
                        <p style="opacity:.7">{{ __('fleet.inspections.none_missing') }}</p>
                    @endforelse
                </div>
            @endif

            <div>
                <div style="font-weight:600;margin-bottom:6px">{{ __('fleet.inspections.open_problems') }}</div>
                @forelse ($problems as $problem)
                    <a href="{{ ServiceRequestResource::getUrl('index') }}" style="display:block;padding:8px 0;border-bottom:1px solid rgba(127,127,127,.2);color:inherit;text-decoration:none">
                        <div style="display:flex;justify-content:space-between;gap:8px">
                            <strong>{{ $problem->vehicle->plate }}</strong>
                            <x-filament::badge :color="$problem->status->getColor()">{{ $problem->status->getLabel() }}</x-filament::badge>
                        </div>
                        <div style="font-size:13px;opacity:.8;margin-top:2px">{{ \Illuminate\Support\Str::limit($problem->description, 90) }}</div>
                    </a>
                @empty
                    <p style="opacity:.7">{{ __('fleet.inspections.no_problems') }}</p>
                @endforelse
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
