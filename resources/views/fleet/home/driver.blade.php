@php
    use App\Modules\Fleet\Filament\FuelUps\FuelUpResource;
    use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
    use App\Modules\Fleet\Services\FleetFormat;
    use App\Modules\Fleet\Services\FuelService;
@endphp

<div style="display:flex;flex-direction:column;gap:16px">
    @foreach ($openFuelUps as $fuelUp)
        <x-filament::section icon="heroicon-o-qr-code">
            <x-slot name="heading">{{ __('fleet.home.open_code', ['plate' => $fuelUp->vehicle->plate]) }}</x-slot>
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px">
                <div>
                    <div style="font-size:2.2rem;font-weight:bold;letter-spacing:.2em;font-family:monospace">{{ FuelService::formatCode($fuelUp->code) }}</div>
                    <div style="font-size:13px;opacity:.75">
                        {{ $fuelUp->codeIsValid()
                            ? __('fleet.fuel.code_valid_until', ['time' => $fuelUp->code_expires_at->timezone(\App\Modules\Fleet\Filament\FleetFields::timezone())->format('H:i')])
                            : __('fleet.fuel.code_expired') }}
                    </div>
                </div>
                <x-filament::button tag="a" size="xl" color="success" icon="heroicon-o-check-circle" :href="FuelUpResource::getUrl('view', ['record' => $fuelUp])">
                    {{ __('fleet.fuel.finish') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endforeach

    @if ($showVehicles)
    <x-filament::section :icon="$module->icon()">
        <x-slot name="heading">{{ __('fleet.home.my_vehicles') }}</x-slot>

        @if ($canFuel)
            <x-slot name="afterHeader">
                <x-filament::button tag="a" icon="heroicon-o-qr-code" :href="FuelUpResource::getUrl('request')">
                    {{ __('fleet.home.fuel_up') }}
                </x-filament::button>
            </x-slot>
        @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
            @forelse ($vehicles as $vehicle)
                <div style="border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:14px">
                    <div style="font-size:1.3rem;font-weight:bold;letter-spacing:.05em">{{ $vehicle->plate }}</div>
                    <div style="opacity:.75;margin-bottom:8px">{{ $vehicle->brand }} {{ $vehicle->model }}</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                        <span style="font-weight:600">{{ FleetFormat::distance($vehicle->odometer) }}</span>
                        <x-filament::badge :color="$vehicle->oilChangeStatus()->getColor()">
                            {{ __('fleet.fields.oil_short') }}: {{ $vehicle->oilChangeStatus()->getLabel() }}
                        </x-filament::badge>
                    </div>
                    <div style="display:flex;gap:8px">
                        @if ($canFuel)
                            <x-filament::button tag="a" size="lg" icon="heroicon-o-beaker" style="flex:1" :href="FuelUpResource::getUrl('request', ['vehicle' => $vehicle->getKey()])">
                                {{ __('fleet.home.fuel_up') }}
                            </x-filament::button>
                        @endif
                        <x-filament::button tag="a" size="lg" color="gray" :href="VehicleResource::getUrl('view', ['record' => $vehicle])">
                            {{ __('fleet.home.open_vehicle') }}
                        </x-filament::button>
                    </div>
                    @if ($canInspect)
                        @php($due = in_array($vehicle->getKey(), $dueInspection, true))
                        <x-filament::button tag="a" size="lg" :color="$due ? 'primary' : 'gray'" icon="heroicon-o-clipboard-document-check" style="width:100%;margin-top:8px"
                            :href="\App\Modules\Fleet\Filament\Inspections\InspectionResource::getUrl('do', ['vehicle' => $vehicle->getKey()])">
                            {{ $due ? __('fleet.inspections.do_pending') : __('fleet.inspections.do') }}
                        </x-filament::button>
                    @endif
                    @if ($canFuel)
                        <x-filament::button tag="a" size="lg" color="warning" icon="heroicon-o-wrench-screwdriver" style="width:100%;margin-top:8px"
                            :href="\App\Modules\Fleet\Filament\Requests\ServiceRequestResource::getUrl('index', ['new' => 1, 'vehicle' => $vehicle->getKey()])">
                            {{ __('fleet.requests.create') }}
                        </x-filament::button>
                    @endif
                </div>
            @empty
                <p style="opacity:.75">{{ __('fleet.home.no_vehicles') }}</p>
            @endforelse
        </div>
    </x-filament::section>

    {{-- What the driver asked and what happened to it. --}}
    @if ($openRequests->isNotEmpty())
        <x-filament::section icon="heroicon-o-chat-bubble-left-ellipsis">
            <x-slot name="heading">{{ __('fleet.home.my_requests') }}</x-slot>
            @foreach ($openRequests as $request)
                <div style="display:flex;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid rgba(127,127,127,.2)">
                    <div>
                        <strong>{{ $request->vehicle->plate }}</strong> · {{ $request->kind->getLabel() }}
                        <div style="font-size:13px;opacity:.75">{{ \Illuminate\Support\Str::limit($request->description, 80) }}</div>
                    </div>
                    <x-filament::badge :color="$request->status->getColor()">{{ $request->status->getLabel() }}</x-filament::badge>
                </div>
            @endforeach
        </x-filament::section>
    @endif
    @endif
</div>
